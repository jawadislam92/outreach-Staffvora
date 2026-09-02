<?php

namespace App\Services\Ai;

final class GeneratedDraft
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $notes,
        public readonly string $model,
        public readonly ?string $effort,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $durationMs = 0,
    ) {}
}
