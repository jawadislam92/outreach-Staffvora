<?php

namespace App\Services\Ai;

use App\Enums\AiRequestStatus;
use App\Enums\DraftSource;
use App\Enums\DraftStatus;
use App\Enums\LeadStatus;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Draft;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Browser-driven, worker-free generation loop. Each call claims a small
 * batch of pending leads under a row lock (so two people clicking at once
 * take different rows), generates sequentially, and reports progress. State
 * lives entirely in the leads table, so closing the tab just pauses.
 */
class GenerationRunner
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private DraftGenerator $generator) {}

    /**
     * @return array{processed: int, generated: int, failed: int, stopped: ?string, last_error: ?string, progress: array}
     */
    public function run(Campaign $campaign, User $actor, ?int $batch = null): array
    {
        $batch ??= (int) config('outreach.generate_batch', 2);
        $this->releaseStale($campaign);

        $summary = ['processed' => 0, 'generated' => 0, 'failed' => 0, 'stopped' => null, 'last_error' => null];

        if (! $this->generator->configured()) {
            $summary['stopped'] = 'not_configured';
            $summary['last_error'] = 'AI drafting is not configured — set ANTHROPIC_API_KEY on the server.';

            return $summary + ['progress' => $this->progress($campaign)];
        }

        $generated = AiRequest::query()->where('campaign_id', $campaign->id)->where('status', AiRequestStatus::Ok->value)->count();
        if ($generated >= $campaign->max_generations) {
            $summary['stopped'] = 'budget';
            $summary['last_error'] = "This campaign reached its generation limit of {$campaign->max_generations}. Raise it in the campaign settings to continue.";

            return $summary + ['progress' => $this->progress($campaign)];
        }

        $claimed = $this->claim($campaign, min($batch, max(1, $campaign->max_generations - $generated)));

        foreach ($claimed as $lead) {
            $summary['processed']++;
            $outcome = $this->generateFor($campaign, $lead, $actor);

            if ($outcome === 'ok') {
                $summary['generated']++;
            } elseif ($outcome === 'transient') {
                $summary['stopped'] = 'rate_limited';
                $summary['last_error'] = $lead->generation_error;
                // Release anything else we claimed in this batch.
                foreach ($claimed as $other) {
                    if ($other->isNot($lead) && $other->fresh()?->status === LeadStatus::Generating) {
                        $other->forceFill(['status' => LeadStatus::Pending, 'locked_at' => null])->save();
                    }
                }
                break;
            } else {
                $summary['failed']++;
                $summary['last_error'] = $lead->generation_error;
            }
        }

        return $summary + ['progress' => $this->progress($campaign)];
    }

    /** Re-run generation for one lead, replacing its draft (the reviewer asked for another take). */
    public function regenerate(Lead $lead, User $actor): Lead
    {
        if (! $this->generator->configured()) {
            throw new \RuntimeException('AI drafting is not configured — set ANTHROPIC_API_KEY on the server.');
        }

        $lead->forceFill(['status' => LeadStatus::Generating, 'locked_at' => now(), 'generation_attempts' => 0])->save();
        $outcome = $this->generateFor($lead->campaign, $lead, $actor);

        if ($outcome !== 'ok') {
            throw new \RuntimeException($lead->generation_error ?: 'Generation failed.');
        }

        return $lead->refresh();
    }

    /** @return array<string, mixed> */
    public function progress(Campaign $campaign): array
    {
        $byStatus = DB::table('leads')
            ->selectRaw('status, COUNT(*) as n')
            ->where('campaign_id', $campaign->id)
            ->groupBy('status')
            ->pluck('n', 'status');

        $cost = DB::table('ai_requests')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('COUNT(*) as calls, SUM(estimated_cost_usd) as cost, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->first();

        $counts = [];
        foreach (LeadStatus::cases() as $case) {
            $counts[$case->value] = (int) ($byStatus[$case->value] ?? 0);
        }

        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'remaining' => $counts['pending'],
            'ai_calls' => (int) ($cost->calls ?? 0),
            'ai_cost_usd' => round((float) ($cost->cost ?? 0), 4),
            'ai_input_tokens' => (int) ($cost->input_tokens ?? 0),
            'ai_output_tokens' => (int) ($cost->output_tokens ?? 0),
            'max_generations' => $campaign->max_generations,
        ];
    }

    /** Leads stuck in `generating` (a request died) go back to pending. */
    public function releaseStale(Campaign $campaign): int
    {
        $cutoff = now()->subMinutes((int) config('outreach.generating_stale_minutes', 5));

        return Lead::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', LeadStatus::Generating->value)
            ->where('locked_at', '<', $cutoff)
            ->update(['status' => LeadStatus::Pending->value, 'locked_at' => null]);
    }

    /** @return list<Lead> */
    private function claim(Campaign $campaign, int $limit): array
    {
        return DB::transaction(function () use ($campaign, $limit) {
            $leads = Lead::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', LeadStatus::Pending->value)
                ->where('generation_attempts', '<', self::MAX_ATTEMPTS)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($leads as $lead) {
                $lead->forceFill(['status' => LeadStatus::Generating, 'locked_at' => now()])->save();
            }

            return $leads->all();
        });
    }

    /** @return 'ok'|'transient'|'failed' */
    private function generateFor(Campaign $campaign, Lead $lead, User $actor): string
    {
        $started = microtime(true);

        try {
            $draft = $this->generator->generate($campaign, $lead);
        } catch (AiTransientException $e) {
            $this->record($campaign, $lead, $actor, AiRequestStatus::Failed, null, $e->getMessage(), $started);
            $lead->forceFill(['status' => LeadStatus::Pending, 'locked_at' => null, 'generation_error' => $e->getMessage()])->save();

            return 'transient';
        } catch (AiRefusedException $e) {
            $this->record($campaign, $lead, $actor, AiRequestStatus::Refused, null, $e->getMessage(), $started);

            return $this->failAttempt($lead, 'The model declined to write this one: '.$e->getMessage());
        } catch (Throwable $e) {
            $this->record($campaign, $lead, $actor, AiRequestStatus::Failed, null, $e->getMessage(), $started);

            return $this->failAttempt($lead, $e->getMessage());
        }

        $this->record($campaign, $lead, $actor, AiRequestStatus::Ok, $draft, null, $started);

        DB::transaction(function () use ($lead, $campaign, $draft, $actor) {
            $existing = Draft::query()->where('lead_id', $lead->id)->first();
            $payload = [
                'campaign_id' => $campaign->id,
                'subject' => mb_substr(trim($draft->subject), 0, 255),
                'body_md' => trim($draft->body),
                'source' => DraftSource::Ai,
                'model' => $draft->model,
                'input_tokens' => $draft->inputTokens,
                'output_tokens' => $draft->outputTokens,
                'cache_read_tokens' => $draft->cacheReadTokens,
                'generation_count' => ($existing?->generation_count ?? 0) + 1,
                'status' => DraftStatus::Draft,
                'edited_by' => $actor->id,
            ];
            if ($existing) {
                $existing->fill($payload)->save();
            } else {
                Draft::query()->create($payload + ['lead_id' => $lead->id]);
            }

            $lead->forceFill([
                'status' => LeadStatus::Ready,
                'locked_at' => null,
                'generation_attempts' => $lead->generation_attempts + 1,
                'generation_error' => null,
            ])->save();
        });

        return 'ok';
    }

    private function failAttempt(Lead $lead, string $error): string
    {
        $attempts = $lead->generation_attempts + 1;
        $lead->forceFill([
            'generation_attempts' => $attempts,
            'generation_error' => mb_substr($error, 0, 1000),
            'locked_at' => null,
            'status' => $attempts >= self::MAX_ATTEMPTS ? LeadStatus::Failed : LeadStatus::Pending,
        ])->save();

        return 'failed';
    }

    private function record(Campaign $campaign, Lead $lead, User $actor, AiRequestStatus $status, ?GeneratedDraft $draft, ?string $error, float $started): void
    {
        $model = $draft?->model ?? app(\App\Support\Settings::class)->aiModel();

        AiRequest::query()->create([
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'user_id' => $actor->id,
            'model' => $model,
            'effort' => $draft?->effort,
            'input_tokens' => $draft?->inputTokens ?? 0,
            'output_tokens' => $draft?->outputTokens ?? 0,
            'cache_read_input_tokens' => $draft?->cacheReadTokens ?? 0,
            'cache_creation_input_tokens' => $draft?->cacheWriteTokens ?? 0,
            'duration_ms' => $draft?->durationMs ?: (int) round((microtime(true) - $started) * 1000),
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'estimated_cost_usd' => $draft ? AiRequest::estimateCost($model, $draft->inputTokens, $draft->outputTokens, $draft->cacheReadTokens, $draft->cacheWriteTokens) : 0,
        ]);
    }
}
