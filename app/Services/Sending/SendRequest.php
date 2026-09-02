<?php

namespace App\Services\Sending;

use App\Models\EmailAccount;
use App\Models\User;

/** Everything SendService needs to reserve, build and deliver one message. */
final class SendRequest
{
    /**
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<string>  $attachmentIds
     * @param  array<string, string>  $variables  template variables ({{first_name}} …)
     */
    public function __construct(
        public readonly User $user,
        public readonly EmailAccount $account,
        public readonly string $to,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $bodyMd,
        public readonly string $clientToken,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $attachmentIds = [],
        public readonly array $variables = [],
        public readonly bool $ackDuplicate = false,
        public readonly ?int $campaignId = null,
        public readonly ?int $leadId = null,
        public readonly ?int $draftId = null,
    ) {}
}
