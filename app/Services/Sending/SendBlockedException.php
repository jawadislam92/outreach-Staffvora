<?php

namespace App\Services\Sending;

use RuntimeException;

/**
 * A send that was refused BEFORE anything was reserved or transmitted.
 * `reason` is a stable machine code; `details` carries UI context.
 */
class SendBlockedException extends RuntimeException
{
    public const INVALID_RECIPIENT = 'invalid_recipient';

    public const NOT_ALLOWED = 'not_allowed';

    public const ACCOUNT_UNUSABLE = 'account_unusable';

    public const SUPPRESSED = 'suppressed';

    public const DUPLICATE_ACK_REQUIRED = 'duplicate_ack_required';

    public const CAP_REACHED = 'cap_reached';

    public const MEMBER_CAP_REACHED = 'member_cap_reached';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** The compose-form field the message belongs to. */
    public function field(): string
    {
        return match ($this->reason) {
            self::INVALID_RECIPIENT, self::SUPPRESSED, self::DUPLICATE_ACK_REQUIRED => 'to',
            default => 'email_account_id',
        };
    }
}
