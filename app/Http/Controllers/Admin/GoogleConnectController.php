<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountProvider;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Services\Sending\Google\GoogleOAuth;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connect (or reconnect) a Gmail mailbox with the send-only scope.
 * State lives in the session and expires after ten minutes.
 */
class GoogleConnectController extends Controller
{
    private const SESSION_KEY = 'google_oauth';

    public function connect(Request $request, GoogleOAuth $google): RedirectResponse
    {
        if (! $google->configured()) {
            return redirect()->route('admin.accounts.index')->with('error', 'Google OAuth is not configured — set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env first.');
        }

        $reconnect = null;
        if ($request->filled('account')) {
            $reconnect = EmailAccount::query()->where('provider', AccountProvider::GmailOauth->value)->findOrFail((int) $request->query('account'));
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'account_id' => $reconnect?->id,
            'user_id' => $request->user()->id,
            'expires' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($google->authorizationUrl($state, $reconnect?->secret('google_email')));
    }

    public function callback(Request $request, GoogleOAuth $google, Settings $settings): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $fail = fn (string $message) => redirect()->route('admin.accounts.index')->with('error', $message);

        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->timestamp || ($pending['user_id'] ?? null) !== $request->user()->id) {
            return $fail('The Google connection attempt expired or did not start from this session. Please try again.');
        }
        $state = (string) $request->query('state', '');
        if ($state === '' || ! hash_equals((string) $pending['state'], $state)) {
            abort(400, 'Invalid OAuth state.');
        }
        if ($request->filled('error')) {
            return $fail($request->query('error') === 'access_denied'
                ? 'You cancelled the Google permission screen — nothing was connected.'
                : 'Google returned an error: '.$request->query('error'));
        }

        try {
            $tokens = $google->exchangeCode((string) $request->query('code', ''));
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }

        if (! $tokens['email']) {
            return $fail('Google did not tell us which mailbox was connected. Make sure the "email" permission stays ticked.');
        }
        if (! $tokens['refresh_token']) {
            return $fail('Google did not return a refresh token. Remove "Outreach" at myaccount.google.com/permissions, then connect again.');
        }

        $reconnect = isset($pending['account_id']) ? EmailAccount::query()->find($pending['account_id']) : null;
        if ($reconnect && strtolower((string) $reconnect->secret('google_email')) !== $tokens['email']) {
            return $fail("You signed in as {$tokens['email']}, but this account is {$reconnect->secret('google_email')}. Reconnect with the same mailbox.");
        }

        // Connecting a mailbox that is already connected just refreshes it.
        $account = $reconnect ?? EmailAccount::query()
            ->where('provider', AccountProvider::GmailOauth->value)
            ->where('from_email', $tokens['email'])
            ->first();

        $isNew = $account === null;
        if ($isNew) {
            $account = EmailAccount::query()->create([
                'label' => $tokens['email'],
                'provider' => AccountProvider::GmailOauth,
                'from_name' => $request->user()->name,
                'from_email' => $tokens['email'],
                'daily_cap' => $settings->defaultDailyCap(),
                'status' => AccountStatus::Active,
                'created_by' => $request->user()->id,
            ]);
        }

        $account->setSecrets([
            'refresh_token' => $tokens['refresh_token'],
            'access_token' => $tokens['access_token'],
            'expires_at' => now()->addSeconds($tokens['expires_in'])->toIso8601String(),
            'google_email' => $tokens['email'],
            'scope' => $tokens['scope'],
        ]);
        $account->forceFill(['status' => AccountStatus::Active, 'last_error' => null, 'last_test_at' => now()])->save();

        AuditLog::record($isNew ? 'account.connect_gmail' : 'account.reconnect_gmail', $request->user(), 'email_account', $account->id, null, ['email' => $tokens['email'], 'scope' => $tokens['scope']]);

        return redirect()->route('admin.accounts.index')->with('success', $isNew
            ? "{$tokens['email']} is connected (send-only). Set its display name, cap and who may use it."
            : "{$tokens['email']} was reconnected.");
    }
}
