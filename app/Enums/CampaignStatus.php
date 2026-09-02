<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Done = 'done';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Done => 'Done',
            self::Archived => 'Archived',
        };
    }
}
