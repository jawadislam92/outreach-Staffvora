<?php

namespace App\Enums;

enum LeadStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs draft',
            self::Generating => 'Generating…',
            self::Ready => 'Ready to send',
            self::Sent => 'Sent',
            self::Skipped => 'Skipped',
            self::Failed => 'Generation failed',
        };
    }
}
