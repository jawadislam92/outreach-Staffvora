<?php

namespace App\Services\Sending;

use App\Enums\AccountProvider;
use App\Models\EmailAccount;
use App\Services\Sending\Connectors\GmailApiConnector;
use App\Services\Sending\Connectors\SmtpConnector;
use App\Services\Sending\Google\GoogleTokenService;

class DefaultConnectorFactory implements ConnectorFactory
{
    public function __construct(private GoogleTokenService $tokens) {}

    public function make(EmailAccount $account): SendConnector
    {
        return match ($account->provider) {
            AccountProvider::Smtp => new SmtpConnector($account),
            AccountProvider::GmailOauth => new GmailApiConnector($account, $this->tokens),
        };
    }
}
