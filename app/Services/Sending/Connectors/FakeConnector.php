<?php

namespace App\Services\Sending\Connectors;

use App\Models\EmailAccount;
use App\Services\Sending\ConnectorFactory;
use App\Services\Sending\SendConnector;
use App\Services\Sending\SendResult;
use App\Services\Sending\SendTimeoutException;
use Symfony\Component\Mime\Email;

/**
 * Test double: records every message and can be told to fail or time out.
 * Bind it as the ConnectorFactory in tests: FakeConnector::install().
 */
class FakeConnector implements SendConnector, ConnectorFactory
{
    /** @var list<array{account: int, email: Email}> */
    public array $sent = [];

    public ?\Throwable $nextFailure = null;

    public bool $timeout = false;

    public int $testCalls = 0;

    private ?EmailAccount $current = null;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(ConnectorFactory::class, $fake);

        return $fake;
    }

    public function make(EmailAccount $account): SendConnector
    {
        $this->current = $account;

        return $this;
    }

    public function send(Email $email): SendResult
    {
        if ($this->timeout) {
            throw new SendTimeoutException('Simulated timeout');
        }
        if ($this->nextFailure) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;
            throw $failure;
        }

        $this->sent[] = ['account' => $this->current?->id, 'email' => $email];

        return new SendResult(
            providerMessageId: 'fake-'.count($this->sent),
            messageIdHeader: $email->getHeaders()->get('Message-ID')?->getBodyAsString(),
        );
    }

    public function test(): void
    {
        $this->testCalls++;
        if ($this->nextFailure) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;
            throw $failure;
        }
    }

    public function last(): ?Email
    {
        return $this->sent[count($this->sent) - 1]['email'] ?? null;
    }
}
