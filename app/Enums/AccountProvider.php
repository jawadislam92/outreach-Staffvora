<?php

namespace App\Enums;

enum AccountProvider: string
{
    case Smtp = 'smtp';
    case GmailOauth = 'gmail_oauth';

    public function label(): string
    {
        return match ($this) {
            self::Smtp => 'SMTP',
            self::GmailOauth => 'Gmail (send-only OAuth)',
        };
    }
}
