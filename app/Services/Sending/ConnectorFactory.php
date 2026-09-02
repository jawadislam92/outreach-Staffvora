<?php

namespace App\Services\Sending;

use App\Models\EmailAccount;

interface ConnectorFactory
{
    public function make(EmailAccount $account): SendConnector;
}
