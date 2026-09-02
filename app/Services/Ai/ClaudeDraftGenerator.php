<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Campaign;
use App\Models\Lead;
use App\Support\Settings;

/**
 * One Claude call per lead through the official SDK. Structured JSON output
 * ({subject, body, personalization_notes}), adaptive thinking, the campaign
 * brief cached as the system prefix, and the server-side refusal fallback
 * enabled so a rare policy decline is retried on a substitute model.
 */
class ClaudeDraftGenerator implements DraftGenerator
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'subject' => ['type' => 'string', 'description' => 'Email subject line, under 60 characters'],
            'body' => ['type' => 'string', 'description' => 'Email body in Markdown, 70-140 words, paragraphs separated by blank lines, no signature'],
            'personalization_notes' => ['type' => 'string', 'description' => 'One sentence: which recipient facts were used'],
        ],
        'required' => ['subject', 'body', 'personalization_notes'],
        'additionalProperties' => false,
    ];

    private ?Client $client = null;

    public function __construct(private Settings $settings) {}

    public function configured(): bool
    {
        return filled(config('outreach.anthropic.api_key'));
    }

    public function generate(Campaign $campaign, Lead $lead): GeneratedDraft
    {
        if (! $this->configured()) {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $model = $this->settings->aiModel();
        $effort = $this->settings->aiEffort();
        $started = microtime(true);

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: 8000,
                messages: [['role' => 'user', 'content' => DraftPrompt::user($lead)]],
                model: $model,
                system: [
                    ['type' => 'text', 'text' => DraftPrompt::system($campaign), 'cacheControl' => ['type' => 'ephemeral']],
                ],
                thinking: ['type' => 'adaptive'],
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            throw new AiTransientException($this->describe($e), 0, $e);
        } catch (APIStatusException $e) {
            if (($e->status ?? 0) === 529 || ($e->status ?? 0) >= 500) {
                throw new AiTransientException($this->describe($e), 0, $e);
            }
            throw new \RuntimeException($this->describe($e), 0, $e);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $usage = $message->usage;
        $tokens = [
            'in' => (int) ($usage->inputTokens ?? 0),
            'out' => (int) ($usage->outputTokens ?? 0),
            'read' => (int) ($usage->cacheReadInputTokens ?? 0),
            'write' => (int) ($usage->cacheCreationInputTokens ?? 0),
        ];

        if ($message->stopReason === 'refusal') {
            $why = $message->stopDetails?->explanation ?? $message->stopDetails?->category ?? 'policy';
            throw new AiRefusedException((string) $why);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new \RuntimeException('The model ran out of room before finishing the draft.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $data = json_decode(trim($text), true);
        if (! is_array($data) || trim((string) ($data['subject'] ?? '')) === '' || trim((string) ($data['body'] ?? '')) === '') {
            throw new \RuntimeException('The model returned an unreadable draft.');
        }

        return new GeneratedDraft(
            subject: trim((string) $data['subject']),
            body: trim((string) $data['body']),
            notes: isset($data['personalization_notes']) ? trim((string) $data['personalization_notes']) : null,
            model: (string) ($message->model ?? $model),
            effort: $effort,
            inputTokens: $tokens['in'],
            outputTokens: $tokens['out'],
            cacheReadTokens: $tokens['read'],
            cacheWriteTokens: $tokens['write'],
            durationMs: $durationMs,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('outreach.anthropic.api_key'));
    }

    private function describe(\Throwable $e): string
    {
        $status = $e instanceof APIStatusException ? ($e->status ?? null) : null;
        $prefix = match (true) {
            $e instanceof RateLimitException => 'Anthropic rate limit (429)',
            $status === 529 => 'Anthropic is overloaded (529)',
            $e instanceof InternalServerException => 'Anthropic server error',
            $e instanceof APIConnectionException => 'Could not reach Anthropic',
            $status === 401 => 'Anthropic rejected the API key (401)',
            $status === 400 => 'Anthropic rejected the request (400)',
            default => 'Anthropic API error'.($status ? " ({$status})" : ''),
        };

        return $prefix.': '.mb_substr($e->getMessage(), 0, 300);
    }
}
