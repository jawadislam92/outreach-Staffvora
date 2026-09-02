<?php

namespace App\Services\Sending\Connectors;

use App\Models\EmailAccount;
use App\Services\Sending\Google\GoogleTokenService;
use App\Services\Sending\MimeBuilder;
use App\Services\Sending\SendConnector;
use App\Services\Sending\SendResult;
use App\Services\Sending\SendTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/**
 * Gmail REST `users.messages.send` with a raw RFC 2822 message. The only
 * scope the app holds is gmail.send, so this class is the whole Gmail
 * surface — there is nothing that could read a mailbox.
 */
class GmailApiConnector implements SendConnector
{
    public const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public const PROFILE_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function __construct(private EmailAccount $account, private GoogleTokenService $tokens) {}

    public function send(Email $email): SendResult
    {
        $raw = MimeBuilder::base64Url(MimeBuilder::toGmailRaw($email));
        $token = $this->tokens->accessTokenFor($this->account);

        $response = $this->post($token, $raw);

        // A stale access token on a mailbox whose refresh token is still valid.
        if ($response->status() === 401) {
            $token = $this->tokens->refresh($this->account);
            $response = $this->post($token, $raw);
        }

        if ($response->failed()) {
            throw new \RuntimeException($this->describeFailure($response));
        }

        return new SendResult(
            providerMessageId: $response->json('id'),
            messageIdHeader: $email->getHeaders()->get('Message-ID')?->getBodyAsString(),
            threadId: $response->json('threadId'),
        );
    }

    /** Proves the refresh token is alive and the mailbox identity matches. Sends nothing. */
    public function test(): void
    {
        $token = $this->tokens->refresh($this->account);

        try {
            $response = Http::withToken($token)->timeout(20)->get(self::PROFILE_URL);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach Google: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException($this->describeFailure($response));
        }

        $email = strtolower((string) $response->json('email'));
        $stored = strtolower((string) $this->account->secret('google_email'));
        if ($email !== '' && $stored !== '' && $email !== $stored) {
            throw new \RuntimeException("Google reports this connection belongs to {$email}, not {$stored}.");
        }
    }

    private function post(string $token, string $raw): Response
    {
        try {
            return Http::withToken($token)
                ->timeout(30)
                ->acceptJson()
                ->post(self::SEND_URL, ['raw' => $raw]);
        } catch (ConnectionException $e) {
            throw new SendTimeoutException('Gmail did not answer in time: '.$e->getMessage(), 0, $e);
        }
    }

    private function describeFailure(Response $response): string
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? $response->body());

        return match (true) {
            $status === 403 && str_contains($reason, 'ailyLimit') => 'Gmail daily sending limit reached for this mailbox.',
            $status === 403 && str_contains($reason, 'ateLimit') => 'Gmail rate limit hit — wait a few minutes and try again.',
            $status === 403 => "Gmail refused the request ({$reason}): {$message}",
            $status === 400 => "Gmail rejected the message: {$message}",
            default => "Gmail API error {$status}: {$message}",
        };
    }
}
