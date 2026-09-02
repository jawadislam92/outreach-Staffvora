<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\EmailAccount;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public const THEME_COOKIE = 'outreach_theme';

    public const THEMES = ['light', 'dark', 'black'];

    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $theme = (string) $request->cookie(self::THEME_COOKIE);

        $needsReconnect = 0;
        if ($user?->isAdmin()) {
            $needsReconnect = EmailAccount::query()->where('status', AccountStatus::NeedsReconnect->value)->count();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->toSummary(),
            ],
            'app' => [
                'name' => config('app.name'),
                'timezone' => $user ? app(Settings::class)->timezone() : 'UTC',
                'accounts_needing_reconnect' => $needsReconnect,
            ],
            'theme' => in_array($theme, self::THEMES, true) ? $theme : 'light',
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                // One-time credentials shown after an admin creates/resets a user.
                'credentials' => $request->session()->get('credentials'),
            ],
        ];
    }
}
