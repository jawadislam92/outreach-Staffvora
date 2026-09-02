<?php

namespace App\Services\Sending\Google;

use App\Enums\AccountStatus;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Access-token lifecycle for Gmail accounts. Refresh tokens live in the
 * encrypted `secrets` blob; `invalid_grant` (revoked, password changed,
 * unused for 6 months) flips the account to needs_reconnect.
 */
class GoogleTokenService
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * @throws GoogleReconnectRequiredException
     * @throws \RuntimeException on transient failures
     */
    public function accessTokenFor(EmailAccount $account): string
    {
        $expiresAt = $account->secret('expires_at');
        $token = $account->secret('access_token');

        if ($token && $expiresAt && CarbonImmutable::parse($expiresAt)->subSeconds(60)->isFuture()) {
            return $token;
        }

        return $this->refresh($account);
    }

    /**
     * @throws GoogleReconnectRequiredException
     */
    public function refresh(EmailAccount $account): string
    {
        $refreshToken = $account->secret('refresh_token');
        if (! $refreshToken) {
            $this->markNeedsReconnect($account, 'No refresh token stored.');
            throw new GoogleReconnectRequiredException('This Gmail account needs to be reconnected.');
        }

        try {
            $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
                'client_id' => config('outreach.google.client_id'),
                'client_secret' => config('outreach.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach Google to refresh the access token: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $error = (string) $response->json('error', 'unknown_error');
            $description = (string) $response->json('error_description', '');

            if (in_array($error, ['invalid_grant', 'unauthorized_client', 'invalid_client'], true) || $response->status() === 401) {
                $this->markNeedsReconnect($account, trim("Google rejected the stored token ({$error}). {$description}"));
                throw new GoogleReconnectRequiredException('Google access was revoked or expired — reconnect this Gmail account.');
            }

            throw new \RuntimeException(trim("Google token refresh failed ({$error}). {$description}"));
        }

        $accessToken = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        $account->setSecrets([
            'access_token' => $accessToken,
            'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
        ]);

        if ($account->status === AccountStatus::NeedsReconnect) {
            $account->forceFill(['status' => AccountStatus::Active, 'last_error' => null])->save();
        }

        return $accessToken;
    }

    public function markNeedsReconnect(EmailAccount $account, string $reason): void
    {
        $account->forceFill([
            'status' => AccountStatus::NeedsReconnect,
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }
}
