<?php

namespace App\Enums;

enum DraftStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Skipped = 'skipped';
}
