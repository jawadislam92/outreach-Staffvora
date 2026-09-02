<?php

namespace App\Services\Sending;

use App\Enums\SendStatus;
use App\Models\SentEmail;

/** "Have we emailed this address before?" for the duplicate warning and imports. */
class RecipientHistory
{
    /**
     * @return array{count: int, last_sent_at: ?string, last_by: ?string, last_account: ?string, last_subject: ?string, campaigns: list<string>}
     */
    public function for(string $emailNormalized): array
    {
        if ($emailNormalized === '') {
            return self::empty();
        }

        // Columns are qualified because one branch below joins `campaigns`,
        // which has its own `status` column.
        $base = SentEmail::query()
            ->where('sent_emails.to_email_normalized', $emailNormalized)
            ->whereIn('sent_emails.status', [SendStatus::Sent->value, SendStatus::Unknown->value]);

        $count = (clone $base)->count();
        if ($count === 0) {
            return self::empty();
        }

        $last = (clone $base)
            ->with(['user:id,name', 'account' => fn ($q) => $q->select('id', 'label', 'deleted_at'), 'campaign:id,name'])
            ->orderByDesc('reserved_at')
            ->first();

        $campaigns = (clone $base)
            ->whereNotNull('campaign_id')
            ->join('campaigns', 'campaigns.id', '=', 'sent_emails.campaign_id')
            ->distinct()
            ->pluck('campaigns.name')
            ->all();

        return [
            'count' => $count,
            'last_sent_at' => ($last?->sent_at ?? $last?->reserved_at)?->toIso8601String(),
            'last_by' => $last?->user?->name,
            'last_account' => $last?->account?->label,
            'last_subject' => $last?->subject,
            'campaigns' => $campaigns,
        ];
    }

    /** @param  list<string>  $emails  normalized → count of prior sends */
    public function countsFor(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return SentEmail::query()
            ->selectRaw('to_email_normalized, COUNT(*) as n')
            ->whereIn('to_email_normalized', $emails)
            ->whereIn('status', [SendStatus::Sent->value, SendStatus::Unknown->value])
            ->groupBy('to_email_normalized')
            ->pluck('n', 'to_email_normalized')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private static function empty(): array
    {
        return ['count' => 0, 'last_sent_at' => null, 'last_by' => null, 'last_account' => null, 'last_subject' => null, 'campaigns' => []];
    }
}
