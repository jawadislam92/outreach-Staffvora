<?php

namespace App\Enums;

enum AiRequestStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Refused = 'refused';
}
