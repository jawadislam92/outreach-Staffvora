<?php

namespace App\Services\Sending;

final class SendResult
{
    public function __construct(
        public readonly ?string $providerMessageId = null,
        public readonly ?string $messageIdHeader = null,
        public readonly ?string $threadId = null,
    ) {}
}
