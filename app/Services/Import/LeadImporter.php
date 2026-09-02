<?php

namespace App\Services\Import;

use App\Enums\DraftSource;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Draft;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Sending\RecipientHistory;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Creates leads (and CSV-supplied drafts) from parsed rows. Rows are never
 * silently dropped: every rejection carries the row number and a reason.
 */
class LeadImporter
{
    public function __construct(private RecipientHistory $history) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, ?string>  $mapping  canonical field → header key
     */
    public function import(Campaign $campaign, array $rows, array $mapping, User $actor): ImportResult
    {
        $result = new ImportResult;
        $emailKey = $mapping['email'] ?? null;
        if (! $emailKey) {
            throw new \InvalidArgumentException('Choose which column holds the email address.');
        }

        $maxAttr = (int) config('outreach.import.max_attribute_chars', 500);
        $maxAttrs = (int) config('outreach.import.max_attributes_chars', 4000);
        $mappedKeys = array_values(array_filter($mapping));

        $existing = Lead::query()->where('campaign_id', $campaign->id)->pluck('email_normalized')->flip()->all();
        $suppressed = Suppression::query()->pluck('email_normalized')->flip()->all();

        $prepared = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $rowNo = $i + 2; // header is line 1
            $parsed = EmailNormalizer::parse($row[$emailKey] ?? '');
            $email = $parsed['email'] ?? '';

            if ($email === '' || ! EmailNormalizer::isValid($email)) {
                $result->rejected[] = ['row' => $rowNo, 'email' => (string) ($row[$emailKey] ?? ''), 'reason' => 'Invalid or missing email address'];

                continue;
            }
            if (($parsed['extra'] ?? 0) > 0) {
                $result->extraAddressesIgnored += $parsed['extra'];
            }
            if (isset($seen[$email])) {
                $result->duplicatesInFile++;

                continue;
            }
            $seen[$email] = true;

            if (isset($existing[$email])) {
                $result->rejected[] = ['row' => $rowNo, 'email' => $email, 'reason' => 'Already in this campaign'];

                continue;
            }
            if (isset($suppressed[$email])) {
                $result->rejected[] = ['row' => $rowNo, 'email' => $email, 'reason' => 'On the do-not-contact list'];

                continue;
            }

            $first = $this->value($row, $mapping['first_name'] ?? null);
            $last = $this->value($row, $mapping['last_name'] ?? null);
            $full = $this->value($row, $mapping['name'] ?? null);
            if ($first === '' && $last === '' && $full !== '') {
                [$first, $last] = array_pad(preg_split('/\s+/u', $full, 2) ?: [], 2, '');
            }
            if ($first === '' && $last === '' && ($parsed['name'] ?? null)) {
                [$first, $last] = array_pad(preg_split('/\s+/u', $parsed['name'], 2) ?: [], 2, '');
            }

            $attributes = [];
            $used = 0;
            foreach ($row as $key => $value) {
                if (in_array($key, $mappedKeys, true) || $value === '') {
                    continue;
                }
                $value = mb_substr($value, 0, $maxAttr);
                if ($used + mb_strlen($value) > $maxAttrs) {
                    break;
                }
                $used += mb_strlen($value);
                $attributes[$key] = $value;
            }

            $subject = $this->value($row, $mapping['subject'] ?? null);
            $body = $this->value($row, $mapping['body'] ?? null);

            $prepared[] = [
                'email' => $parsed['email'],
                'email_normalized' => $email,
                'first_name' => mb_substr($first, 0, 120) ?: null,
                'last_name' => mb_substr($last, 0, 120) ?: null,
                'company' => mb_substr($this->value($row, $mapping['company'] ?? null), 0, 150) ?: null,
                'attributes' => $attributes,
                'import_row' => $rowNo,
                'draft' => ($subject !== '' && $body !== '') ? ['subject' => mb_substr($subject, 0, 255), 'body_md' => $body] : null,
            ];
        }

        if ($prepared === []) {
            return $result;
        }

        $contacted = $this->history->countsFor(array_column($prepared, 'email_normalized'));

        foreach (array_chunk($prepared, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $campaign, $contacted, $actor, $result) {
                foreach ($chunk as $item) {
                    $draft = $item['draft'];
                    unset($item['draft']);
                    $priorCount = (int) ($contacted[$item['email_normalized']] ?? 0);

                    $lead = Lead::query()->create($item + [
                        'campaign_id' => $campaign->id,
                        'status' => $draft ? LeadStatus::Ready : LeadStatus::Pending,
                        'previously_contacted_count' => $priorCount,
                    ]);
                    $result->created++;
                    if ($priorCount > 0) {
                        $result->previouslyContacted++;
                    }

                    if ($draft) {
                        Draft::query()->create([
                            'lead_id' => $lead->id,
                            'campaign_id' => $campaign->id,
                            'subject' => $draft['subject'],
                            'body_md' => $draft['body_md'],
                            'source' => DraftSource::Csv,
                            'edited_by' => $actor->id,
                        ]);
                        $result->withDrafts++;
                    }
                }
            });
        }

        return $result;
    }

    private function value(array $row, ?string $key): string
    {
        return $key ? trim((string) ($row[$key] ?? '')) : '';
    }
}
