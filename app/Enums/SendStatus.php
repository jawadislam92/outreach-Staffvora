<?php

namespace App\Enums;

enum SendStatus: string
{
    /** Reserved against the cap; the network call has not finished. */
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    /** The request timed out after the message may have left — never auto-retried. */
    case Unknown = 'unknown';

    /** Statuses that consume a slot of the daily cap. */
    public static function countedTowardsCap(): array
    {
        return [self::Pending->value, self::Sent->value, self::Unknown->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sending…',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
        };
    }
}
