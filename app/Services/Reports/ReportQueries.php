<?php

namespace App\Services\Reports;

use App\Enums\SendStatus;
use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Read-only aggregates over the send log. Everything is keyed by `local_date`
 * (the business day stamped at reservation time), never by UTC timestamps.
 *
 * Aggregates use the base query builder on purpose: Eloquent would cast the
 * grouped `status` column to the SendStatus enum, which cannot be an array key.
 */
class ReportQueries
{
    /** Gaps longer than this are "first send of the day", not pacing. */
    public const MAX_MEANINGFUL_GAP = 12 * 3600;

    public function __construct(private Settings $settings) {}

    private function log(): Builder
    {
        return DB::table('sent_emails');
    }

    /**
     * Per-account usage for one business day, for every account in $accounts.
     *
     * @param  Collection<int, EmailAccount>  $accounts
     * @return list<array{account: array, sent: int, pending: int, unknown: int, failed: int, used: int, remaining: int, cap: int, last_sent_at: ?string}>
     */
    public function accountUsage(Collection $accounts, ?string $date = null): array
    {
        $date ??= $this->settings->today();
        $ids = $accounts->pluck('id')->all();

        $rows = $this->log()
            ->selectRaw('email_account_id, status, COUNT(*) as n')
            ->whereIn('email_account_id', $ids)
            ->where('local_date', $date)
            ->groupBy('email_account_id', 'status')
            ->get()
            ->groupBy('email_account_id');

        $lastSent = $this->log()
            ->selectRaw('email_account_id, MAX(sent_at) as last_sent_at')
            ->whereIn('email_account_id', $ids)
            ->where('status', SendStatus::Sent->value)
            ->groupBy('email_account_id')
            ->pluck('last_sent_at', 'email_account_id');

        return $accounts->map(function (EmailAccount $account) use ($rows, $lastSent) {
            $byStatus = ($rows[$account->id] ?? collect())->pluck('n', 'status');
            $sent = (int) ($byStatus['sent'] ?? 0);
            $pending = (int) ($byStatus['pending'] ?? 0);
            $unknown = (int) ($byStatus['unknown'] ?? 0);
            $failed = (int) ($byStatus['failed'] ?? 0);
            $used = $sent + $pending + $unknown;
            $last = $lastSent[$account->id] ?? null;

            return [
                'account' => $account->toSummary(),
                'sent' => $sent,
                'pending' => $pending,
                'unknown' => $unknown,
                'failed' => $failed,
                'used' => $used,
                'cap' => $account->daily_cap,
                'remaining' => max(0, $account->daily_cap - $used),
                'last_sent_at' => $last ? CarbonImmutable::parse($last, 'UTC')->toIso8601String() : null,
            ];
        })->values()->all();
    }

    /** @return array{sent: int, failed: int, unknown: int, pending: int, avg_gap: ?int, senders: int} */
    public function teamTotals(?string $date = null): array
    {
        $date ??= $this->settings->today();

        $byStatus = $this->log()
            ->selectRaw('status, COUNT(*) as n')
            ->where('local_date', $date)
            ->groupBy('status')
            ->pluck('n', 'status');

        $avgGap = $this->log()
            ->where('local_date', $date)
            ->where('status', SendStatus::Sent->value)
            ->whereNotNull('gap_seconds')
            ->where('gap_seconds', '<', self::MAX_MEANINGFUL_GAP)
            ->avg('gap_seconds');

        $senders = $this->log()
            ->where('local_date', $date)
            ->where('status', SendStatus::Sent->value)
            ->distinct()
            ->count('user_id');

        return [
            'sent' => (int) ($byStatus['sent'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'unknown' => (int) ($byStatus['unknown'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'avg_gap' => $avgGap === null ? null : (int) round((float) $avgGap),
            'senders' => $senders,
        ];
    }

    /** Sent count for each of the last $days business days, oldest first. */
    public function dailySeries(int $days = 14, ?string $endDate = null): array
    {
        $end = CarbonImmutable::parse($endDate ?? $this->settings->today());
        $start = $end->subDays($days - 1);

        $counts = $this->log()
            ->selectRaw('local_date, COUNT(*) as n')
            ->where('status', SendStatus::Sent->value)
            ->whereBetween('local_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('local_date')
            ->pluck('n', 'local_date')
            ->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);

        $series = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'sent' => (int) ($counts[$d->toDateString()] ?? 0)];
        }

        return $series;
    }

    /** Sent/failed counts per member for one business day. */
    public function memberTotals(?string $date = null): array
    {
        $date ??= $this->settings->today();

        return $this->log()
            ->join('users', 'users.id', '=', 'sent_emails.user_id')
            ->selectRaw(
                'users.id, users.name, users.daily_cap, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as sent, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as failed',
                [SendStatus::Sent->value, SendStatus::Failed->value],
            )
            ->where('sent_emails.local_date', $date)
            ->groupBy('users.id', 'users.name', 'users.daily_cap')
            ->orderByDesc('sent')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'daily_cap' => $r->daily_cap, 'sent' => (int) $r->sent, 'failed' => (int) $r->failed])
            ->all();
    }

    /** Most recent attempts across the team. */
    public function recent(int $limit = 10): array
    {
        return SentEmail::query()
            ->with(['user:id,name', 'account' => fn ($q) => $q->select('id', 'label', 'from_email', 'deleted_at')])
            ->orderByDesc('reserved_at')
            ->limit($limit)
            ->get()
            ->map(fn (SentEmail $e) => [
                'id' => $e->id,
                'to_email' => $e->to_email,
                'subject' => $e->subject,
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'user' => $e->user?->name,
                'account' => $e->account?->label,
                'gap_seconds' => $e->gap_seconds,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'reserved_at' => $e->reserved_at?->toIso8601String(),
                'error' => $e->error,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Date-range reports (Phase 6). Every method takes an inclusive
    // local_date range plus optional account / member filters.
    // ------------------------------------------------------------------

    /** Log rows in the range, optionally narrowed to one account and/or member. */
    private function ranged(string $from, string $to, ?int $accountId = null, ?int $userId = null): Builder
    {
        return $this->log()
            ->whereBetween('sent_emails.local_date', [$from, $to])
            ->when($accountId, fn (Builder $q, int $id) => $q->where('sent_emails.email_account_id', $id))
            ->when($userId, fn (Builder $q, int $id) => $q->where('sent_emails.user_id', $id));
    }

    /** Calendar days covered by an inclusive range (from = to → 1). */
    private function daysInRange(string $from, string $to): int
    {
        return max(1, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);
    }

    /** SQL: the row's gap when it is a paced successful send, otherwise NULL (so AVG/MIN skip it). */
    private function pacedGapSql(): string
    {
        return 'CASE WHEN sent_emails.status = ? AND sent_emails.gap_seconds < ? THEN sent_emails.gap_seconds END';
    }

    /** @return list<int|string> */
    private function pacedGapBindings(): array
    {
        return [SendStatus::Sent->value, self::MAX_MEANINGFUL_GAP];
    }

    /**
     * Pacing statistics over raw gap values, computed in PHP so the median is
     * exact on every driver. Nulls (first send ever) and gaps of
     * MAX_MEANINGFUL_GAP or more (first send of the day) are not pacing and
     * are skipped.
     *
     * @param  iterable<int|string|null>  $gaps
     * @return array{avg_gap: ?int, median_gap: ?int, min_gap: ?int, gap_samples: int}
     */
    public function gapStats(iterable $gaps): array
    {
        $values = [];
        foreach ($gaps as $gap) {
            if ($gap !== null && (int) $gap < self::MAX_MEANINGFUL_GAP) {
                $values[] = (int) $gap;
            }
        }

        $n = count($values);
        if ($n === 0) {
            return ['avg_gap' => null, 'median_gap' => null, 'min_gap' => null, 'gap_samples' => 0];
        }

        sort($values);
        $mid = intdiv($n, 2);

        return [
            'avg_gap' => (int) round(array_sum($values) / $n),
            'median_gap' => $n % 2 === 1 ? $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2),
            'min_gap' => $values[0],
            'gap_samples' => $n,
        ];
    }

    /**
     * Team totals for a range. `senders` and `days_active` count successful sends only.
     *
     * @return array{sent: int, failed: int, unknown: int, pending: int, senders: int, days_active: int, avg_gap: ?int, median_gap: ?int, min_gap: ?int, gap_samples: int}
     */
    public function rangeTotals(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        $byStatus = $this->ranged($from, $to, $accountId, $userId)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $sent = $this->ranged($from, $to, $accountId, $userId)->where('sent_emails.status', SendStatus::Sent->value);

        $gaps = $this->gapStats(
            (clone $sent)->whereNotNull('gap_seconds')->where('gap_seconds', '<', self::MAX_MEANINGFUL_GAP)->pluck('gap_seconds'),
        );

        return [
            'sent' => (int) ($byStatus['sent'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'unknown' => (int) ($byStatus['unknown'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'senders' => (clone $sent)->distinct()->count('user_id'),
            'days_active' => (clone $sent)->distinct()->count('local_date'),
            ...$gaps,
        ];
    }

    /**
     * Per-account volume and pacing, busiest first. Joins email_accounts
     * without the soft-delete scope so deleted mailboxes keep their history.
     * `utilization_pct` = sent ÷ (daily cap × calendar days in the range).
     *
     * @return list<array{account_id: int, label: string, from_email: string, sent: int, failed: int, unknown: int, avg_gap: ?int, min_gap: ?int, cap: int, days: int, utilization_pct: ?float}>
     */
    public function byAccount(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        $days = $this->daysInRange($from, $to);

        return $this->ranged($from, $to, $accountId, $userId)
            ->join('email_accounts', 'email_accounts.id', '=', 'sent_emails.email_account_id')
            ->selectRaw(
                'email_accounts.id as account_id, email_accounts.label, email_accounts.from_email, email_accounts.daily_cap, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as sent, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as failed, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as unknown, '
                .'AVG('.$this->pacedGapSql().') as avg_gap, '
                .'MIN('.$this->pacedGapSql().') as min_gap',
                [SendStatus::Sent->value, SendStatus::Failed->value, SendStatus::Unknown->value, ...$this->pacedGapBindings(), ...$this->pacedGapBindings()],
            )
            ->groupBy('email_accounts.id', 'email_accounts.label', 'email_accounts.from_email', 'email_accounts.daily_cap')
            ->orderByDesc('sent')
            ->orderBy('email_accounts.label')
            ->get()
            ->map(function ($r) use ($days) {
                $cap = (int) $r->daily_cap;
                $sent = (int) $r->sent;

                return [
                    'account_id' => (int) $r->account_id,
                    'label' => $r->label,
                    'from_email' => $r->from_email,
                    'sent' => $sent,
                    'failed' => (int) $r->failed,
                    'unknown' => (int) $r->unknown,
                    'avg_gap' => $r->avg_gap === null ? null : (int) round((float) $r->avg_gap),
                    'min_gap' => $r->min_gap === null ? null : (int) $r->min_gap,
                    'cap' => $cap,
                    'days' => $days,
                    'utilization_pct' => $cap > 0 ? round($sent / ($cap * $days) * 100, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Per-member volume and pacing, busiest first. `accounts_used` counts the
     * distinct mailboxes the member sent from successfully.
     *
     * @return list<array{user_id: int, name: string, sent: int, failed: int, unknown: int, avg_gap: ?int, accounts_used: int}>
     */
    public function byMember(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        return $this->ranged($from, $to, $accountId, $userId)
            ->join('users', 'users.id', '=', 'sent_emails.user_id')
            ->selectRaw(
                'users.id as user_id, users.name, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as sent, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as failed, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as unknown, '
                .'AVG('.$this->pacedGapSql().') as avg_gap, '
                .'COUNT(DISTINCT CASE WHEN sent_emails.status = ? THEN sent_emails.email_account_id END) as accounts_used',
                [SendStatus::Sent->value, SendStatus::Failed->value, SendStatus::Unknown->value, ...$this->pacedGapBindings(), SendStatus::Sent->value],
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('sent')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'sent' => (int) $r->sent,
                'failed' => (int) $r->failed,
                'unknown' => (int) $r->unknown,
                'avg_gap' => $r->avg_gap === null ? null : (int) round((float) $r->avg_gap),
                'accounts_used' => (int) $r->accounts_used,
            ])
            ->values()
            ->all();
    }

    /**
     * Successful sends per account × member for the range. Deliberately
     * unfiltered: the grid is always the whole team. Only accounts/members
     * with at least one send appear; cells are keyed "<account_id>:<user_id>".
     *
     * @return array{accounts: list<array{id: int, label: string}>, members: list<array{id: int, name: string}>, cells: array<string, int>}
     */
    public function pivot(string $from, string $to): array
    {
        $rows = $this->ranged($from, $to)
            ->join('email_accounts', 'email_accounts.id', '=', 'sent_emails.email_account_id')
            ->join('users', 'users.id', '=', 'sent_emails.user_id')
            ->selectRaw('sent_emails.email_account_id, email_accounts.label, sent_emails.user_id, users.name, COUNT(*) as n')
            ->where('sent_emails.status', SendStatus::Sent->value)
            ->groupBy('sent_emails.email_account_id', 'email_accounts.label', 'sent_emails.user_id', 'users.name')
            ->get();

        $cells = [];
        foreach ($rows as $r) {
            $cells[$r->email_account_id.':'.$r->user_id] = (int) $r->n;
        }

        return [
            'accounts' => $rows->unique('email_account_id')
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($r) => ['id' => (int) $r->email_account_id, 'label' => $r->label])
                ->values()
                ->all(),
            'members' => $rows->unique('user_id')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($r) => ['id' => (int) $r->user_id, 'name' => $r->name])
                ->values()
                ->all(),
            'cells' => $cells,
        ];
    }

    /**
     * Sent / failed / unknown counts for every business day in the range,
     * oldest first and zero-filled.
     *
     * @return list<array{date: string, sent: int, failed: int, unknown: int}>
     */
    public function dailyTimeline(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        $rows = $this->ranged($from, $to, $accountId, $userId)
            ->selectRaw('local_date, status, COUNT(*) as n')
            ->groupBy('local_date', 'status')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[substr((string) $r->local_date, 0, 10)][$r->status] = (int) $r->n;
        }

        $series = [];
        $end = CarbonImmutable::parse($to);
        for ($d = CarbonImmutable::parse($from); $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $series[] = [
                'date' => $key,
                'sent' => $byDay[$key]['sent'] ?? 0,
                'failed' => $byDay[$key]['failed'] ?? 0,
                'unknown' => $byDay[$key]['unknown'] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * Successful sends per hour of day (index 0–23) in the business timezone.
     * sent_at is stored in UTC; the conversion happens in PHP, in batches, so
     * it is identical on every driver and honours DST rules.
     *
     * @return list<int>
     */
    public function hourOfDay(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        $tz = $this->settings->timezone();
        $hours = array_fill(0, 24, 0);

        $query = $this->ranged($from, $to, $accountId, $userId)
            ->select('sent_emails.sent_at')
            ->where('sent_emails.status', SendStatus::Sent->value)
            ->whereNotNull('sent_emails.sent_at')
            ->orderBy('sent_emails.sent_at')
            ->orderBy('sent_emails.id');

        foreach ($query->lazy(1000) as $row) {
            $hour = (int) CarbonImmutable::parse($row->sent_at, 'UTC')->setTimezone($tz)->format('G');
            $hours[$hour]++;
        }

        return $hours;
    }

    /**
     * One row per day × account × member that had any attempt, oldest first —
     * the CSV export shape. Lazy so a year of history streams in pages.
     *
     * @return LazyCollection<int, array{date: string, account: string, member: string, sent: int, failed: int, unknown: int, avg_gap: ?int}>
     */
    public function dailyBreakdown(string $from, string $to, ?int $accountId = null, ?int $userId = null): LazyCollection
    {
        return $this->ranged($from, $to, $accountId, $userId)
            ->join('email_accounts', 'email_accounts.id', '=', 'sent_emails.email_account_id')
            ->join('users', 'users.id', '=', 'sent_emails.user_id')
            ->selectRaw(
                'sent_emails.local_date, email_accounts.label as account, users.name as member, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as sent, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as failed, '
                .'SUM(CASE WHEN sent_emails.status = ? THEN 1 ELSE 0 END) as unknown, '
                .'AVG('.$this->pacedGapSql().') as avg_gap',
                [SendStatus::Sent->value, SendStatus::Failed->value, SendStatus::Unknown->value, ...$this->pacedGapBindings()],
            )
            ->groupBy('sent_emails.local_date', 'email_accounts.id', 'email_accounts.label', 'users.id', 'users.name')
            ->orderBy('sent_emails.local_date')
            ->orderBy('email_accounts.label')
            ->orderBy('users.name')
            ->lazy(500)
            ->map(fn ($r) => [
                'date' => substr((string) $r->local_date, 0, 10),
                'account' => $r->account,
                'member' => $r->member,
                'sent' => (int) $r->sent,
                'failed' => (int) $r->failed,
                'unknown' => (int) $r->unknown,
                'avg_gap' => $r->avg_gap === null ? null : (int) round((float) $r->avg_gap),
            ]);
    }
}
