<?php

namespace App\Services\Sending\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Authorization-code flow for the send-only Gmail connection. */
class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const GMAIL_SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function configured(): bool
    {
        return filled(config('outreach.google.client_id'))
            && filled(config('outreach.google.client_secret'))
            && filled(config('outreach.google.redirect_uri'));
    }

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $params = [
            'client_id' => config('outreach.google.client_id'),
            'redirect_uri' => config('outreach.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('outreach.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];
        if ($loginHint) {
            $params['login_hint'] = $loginHint;
        }

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string, email: ?string}
     *
     * @throws \RuntimeException
     */
    public function exchangeCode(string $code): array
    {
        try {
            $response = Http::asForm()->timeout(20)->post(GoogleTokenService::TOKEN_URL, [
                'code' => $code,
                'client_id' => config('outreach.google.client_id'),
                'client_secret' => config('outreach.google.client_secret'),
                'redirect_uri' => config('outreach.google.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach Google to complete the connection: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException('Google rejected the authorization code: '.($response->json('error_description') ?? $response->json('error') ?? $response->body()));
        }

        $scope = (string) $response->json('scope', '');
        if (! str_contains($scope, self::GMAIL_SEND_SCOPE)) {
            throw new \RuntimeException('The "Send email on your behalf" permission was not granted. Tick it on Google\'s consent screen and try again.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
            'scope' => $scope,
            'email' => $this->emailFromIdToken((string) $response->json('id_token', '')),
        ];
    }

    /**
     * The id_token arrived over TLS straight from Google's token endpoint, so
     * only the audience is checked before trusting its email claim.
     */
    public function emailFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);
        if (! is_array($payload)) {
            return null;
        }
        if (($payload['aud'] ?? null) !== config('outreach.google.client_id')) {
            return null;
        }

        $email = strtolower((string) ($payload['email'] ?? ''));

        return $email !== '' ? $email : null;
    }
}
