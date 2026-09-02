<?php

namespace App\Services\Sending\Connectors;

use App\Enums\SmtpEncryption;
use App\Models\EmailAccount;
use App\Services\Sending\SendConnector;
use App\Services\Sending\SendResult;
use App\Services\Sending\SendTimeoutException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * Plain SMTP submission (465 implicit TLS or 587 STARTTLS). Outbound only —
 * the mailbox is never opened, so sent mail does not appear in its Sent
 * folder; the app's log is the record.
 */
class SmtpConnector implements SendConnector
{
    public function __construct(private EmailAccount $account) {}

    public function send(Email $email): SendResult
    {
        $transport = $this->transport();

        try {
            $sent = $transport->send($email, Envelope::create($email));
        } catch (TransportException $e) {
            throw $this->classify($e);
        }

        return new SendResult(
            providerMessageId: $sent->getMessageId(),
            messageIdHeader: $email->getHeaders()->get('Message-ID')?->getBodyAsString(),
        );
    }

    public function test(): void
    {
        $transport = $this->transport();

        try {
            // EHLO + STARTTLS/TLS + AUTH, then QUIT. Nothing is sent.
            $transport->start();
            $transport->stop();
        } catch (TransportException $e) {
            throw $this->classify($e);
        }
    }

    private function transport(): EsmtpTransport
    {
        $host = (string) $this->account->smtp_host;
        $port = (int) $this->account->smtp_port;
        $implicitTls = $this->account->smtp_encryption === SmtpEncryption::Ssl;

        $stream = new SocketStream;
        $stream->setTimeout((int) config('outreach.smtp.timeout_seconds', 20));

        $cafile = config('outreach.smtp.cafile');
        if (filled($cafile)) {
            $stream->setStreamOptions(['ssl' => ['cafile' => $cafile, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        $transport = new EsmtpTransport($host, $port, $implicitTls, null, null, $stream);
        $transport->setUsername((string) $this->account->smtp_username);
        $transport->setPassword((string) $this->account->secret('smtp_password'));

        $local = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        if ($local !== 'localhost' && ! str_contains($local, '.')) {
            $local = 'localhost';
        }
        $transport->setLocalDomain($local);

        return $transport;
    }

    /** Timeouts become "unknown"; everything else is a definite failure with a readable message. */
    private function classify(TransportException $e): \Throwable
    {
        $message = $e->getMessage();

        if (preg_match('/timed? ?out|timeout/i', $message)) {
            return new SendTimeoutException('The mail server did not answer in time: '.$message, 0, $e);
        }

        if (preg_match('/535|authentication failed|username and password not accepted|invalid credentials/i', $message)) {
            return new \RuntimeException('The mail server rejected the username or password (SMTP 535).', 0, $e);
        }

        if (preg_match('/certificate|ssl|tls/i', $message) && preg_match('/verify|handshake|failed/i', $message)) {
            return new \RuntimeException('TLS handshake failed — check the host name, port and encryption setting. '.$message, 0, $e);
        }

        if (preg_match('/getaddrinfo|resolve|could not find|connection refused|unable to connect/i', $message)) {
            return new \RuntimeException('Could not reach the mail server — check the host and port. '.$message, 0, $e);
        }

        return new \RuntimeException($message, 0, $e);
    }
}
