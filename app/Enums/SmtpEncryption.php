<?php

namespace App\Enums;

enum SmtpEncryption: string
{
    /** Implicit TLS, normally port 465. */
    case Ssl = 'ssl';

    /** STARTTLS upgrade, normally port 587. */
    case StartTls = 'starttls';
}
