<?php

namespace App\Services\Sending;

use Symfony\Component\Mime\Email;

/**
 * One outbound-only transport per account type. There is deliberately no
 * receive/read counterpart anywhere in the application.
 */
interface SendConnector
{
    /**
     * Deliver a fully built message.
     *
     * @throws SendTimeoutException when the request timed out after the message may have left
     * @throws \Throwable for definite failures (auth, rejected recipient, quota…)
     */
    public function send(Email $email): SendResult;

    /**
     * Verify credentials/connectivity without sending anything.
     *
     * @throws \Throwable with a human-readable message on failure
     */
    public function test(): void;
}
