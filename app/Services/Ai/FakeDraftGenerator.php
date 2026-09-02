<?php

namespace App\Services\Ai;

use App\Models\Campaign;
use App\Models\Lead;

/** Deterministic stand-in for tests: generates from the lead's data, can be told to fail. */
class FakeDraftGenerator implements DraftGenerator
{
    /** @var list<\Throwable> */
    public array $failures = [];

    public int $calls = 0;

    public bool $isConfigured = true;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(DraftGenerator::class, $fake);

        return $fake;
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function generate(Campaign $campaign, Lead $lead): GeneratedDraft
    {
        $this->calls++;
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $first = $lead->first_name ?: 'there';
        $company = $lead->company ?: 'your team';

        return new GeneratedDraft(
            subject: "Quick idea for {$company}",
            body: "Hi {$first},\n\nI noticed {$company} and thought of one thing we could help with.\n\nWorth a short call?",
            notes: 'fake',
            // A real model id so cost estimates exercise the pricing table.
            model: 'claude-opus-5',
            effort: 'medium',
            inputTokens: 500,
            outputTokens: 120,
            cacheReadTokens: 0,
            cacheWriteTokens: 0,
            durationMs: 5,
        );
    }
}
