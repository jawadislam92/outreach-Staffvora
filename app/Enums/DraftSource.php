<?php

namespace App\Enums;

enum DraftSource: string
{
    case Ai = 'ai';
    case Csv = 'csv';
    case Manual = 'manual';
}
