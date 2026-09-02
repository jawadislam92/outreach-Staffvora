<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Reports\ReportQueries;
use App\Support\CsvExport;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    /** Longest range a single report aggregates, in calendar days (inclusive). */
    private const MAX_RANGE_DAYS = 366;

    public function index(Request $request, ReportQueries $reports, Settings $settings): Response
    {
        $filters = $this->filters($request, $settings);
        ['from' => $from, 'to' => $to, 'account_id' => $accountId, 'user_id' => $userId] = $filters;

        return Inertia::render('Reports/Index', [
            'filters' => $filters,
            'totals' => $reports->rangeTotals($from, $to, $accountId, $userId),
            'by_account' => $reports->byAccount($from, $to, $accountId, $userId),
            'by_member' => $reports->byMember($from, $to, $accountId, $userId),
            'pivot' => $reports->pivot($from, $to),
            'timeline' => $reports->dailyTimeline($from, $to, $accountId, $userId),
            'hour_of_day' => $reports->hourOfDay($from, $to, $accountId, $userId),
            'accounts' => EmailAccount::withTrashed()->orderBy('label')->get(['id', 'label'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->label]),
            'members' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'timezone' => $settings->timezone(),
            'today' => $settings->today(),
        ]);
    }

    public function export(Request $request, ReportQueries $reports, Settings $settings): StreamedResponse
    {
        $filters = $this->filters($request, $settings);

        $rows = $reports->dailyBreakdown($filters['from'], $filters['to'], $filters['account_id'], $filters['user_id'])
            ->map(fn (array $r) => [$r['date'], $r['account'], $r['member'], $r['sent'], $r['failed'], $r['unknown'], $r['avg_gap']]);

        return CsvExport::stream(
            'outreach-report-'.$filters['from'].'-to-'.$filters['to'].'.csv',
            ['Date', 'Account', 'Member', 'Sent', 'Failed', 'Unknown', 'Avg gap (s)'],
            $rows,
            $request->user(),
            'reports.export',
            $filters,
        );
    }

    /**
     * Validated range + optional narrowing. Defaults to the last 30 business
     * days ending today; the end date defaults first so `from` alone or `to`
     * alone both produce a sane window.
     *
     * @return array{from: string, to: string, account_id: ?int, user_id: ?int}
     */
    private function filters(Request $request, Settings $settings): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $to = $validated['to'] ?? $settings->today();
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();

        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Reports cover at most '.self::MAX_RANGE_DAYS.' days at a time.']);
        }

        return [
            'from' => $from,
            'to' => $to,
            'account_id' => isset($validated['account_id']) ? (int) $validated['account_id'] : null,
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
        ];
    }
}
