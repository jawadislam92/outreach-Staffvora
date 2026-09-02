<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Settings $settings): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => [
                'timezone' => $settings->timezone(),
                'default_daily_cap' => $settings->defaultDailyCap(),
                'min_gap_warning_seconds' => $settings->minGapWarningSeconds(),
                'ai_model' => $settings->aiModel(),
                'ai_effort' => $settings->aiEffort(),
                'max_attachment_mb' => (int) $settings->get('max_attachment_mb'),
            ],
            'timezones' => timezone_identifiers_list(),
            'models' => collect(config('outreach.anthropic.models'))->map(fn ($label, $id) => ['value' => $id, 'label' => $label])->values(),
            'efforts' => Settings::EFFORTS,
            // Read-only environment status so setup problems are visible in-app.
            'env' => [
                'google_configured' => filled(config('outreach.google.client_id')) && filled(config('outreach.google.client_secret')),
                'google_redirect_uri' => config('outreach.google.redirect_uri'),
                'anthropic_configured' => filled(config('outreach.anthropic.api_key')),
                'smtp_cafile' => config('outreach.smtp.cafile'),
                'app_url' => config('app.url'),
            ],
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'default_daily_cap' => ['required', 'integer', 'min:0', 'max:10000'],
            'min_gap_warning_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'ai_model' => ['required', 'string', Rule::in(array_keys(config('outreach.anthropic.models')))],
            'ai_effort' => ['required', 'string', Rule::in(Settings::EFFORTS)],
            'max_attachment_mb' => ['required', 'integer', 'min:1', 'max:25'],
        ]);

        foreach ($data as $key => $value) {
            $settings->set($key, $value, $request->user());
        }

        return back()->with('success', 'Settings saved.');
    }
}
