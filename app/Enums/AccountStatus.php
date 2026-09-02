<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case NeedsReconnect = 'needs_reconnect';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::NeedsReconnect => 'Needs reconnect',
            self::Disabled => 'Disabled',
        };
    }
}
