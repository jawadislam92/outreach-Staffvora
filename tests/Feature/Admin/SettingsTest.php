<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'timezone' => 'Asia/Karachi',
            'default_daily_cap' => 30,
            'min_gap_warning_seconds' => 120,
            'ai_model' => 'claude-opus-5',
            'ai_effort' => 'medium',
            'max_attachment_mb' => 10,
        ], $overrides);
    }

    public function test_defaults_apply_before_anything_is_saved(): void
    {
        $settings = app(Settings::class);

        $this->assertSame('Asia/Karachi', $settings->timezone());
        $this->assertSame(30, $settings->defaultDailyCap());
        $this->assertSame('claude-opus-5', $settings->aiModel());
    }

    public function test_admin_can_update_settings_and_changes_are_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/settings', $this->payload([
            'timezone' => 'Europe/London',
            'default_daily_cap' => 25,
            'ai_model' => 'claude-sonnet-5',
        ]))->assertRedirect();

        $settings = app(Settings::class);
        $this->assertSame('Europe/London', $settings->timezone());
        $this->assertSame(25, $settings->defaultDailyCap());
        $this->assertSame('claude-sonnet-5', $settings->aiModel());

        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.update', 'user_id' => $admin->id]);
        // Unchanged keys must not produce audit noise.
        $this->assertSame(3, \App\Models\AuditLog::query()->where('action', 'settings.update')->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/admin/settings')->put('/admin/settings', $this->payload([
            'timezone' => 'Mars/Olympus',
            'ai_model' => 'gpt-9',
            'default_daily_cap' => -1,
        ]))->assertSessionHasErrors(['timezone', 'ai_model', 'default_daily_cap']);
    }

    public function test_local_date_uses_the_business_timezone(): void
    {
        $settings = app(Settings::class);
        $settings->set('timezone', 'Asia/Karachi');

        // 22:30 UTC is already 03:30 the next day in Karachi (UTC+5).
        $at = \Carbon\CarbonImmutable::parse('2026-09-02 22:30:00', 'UTC');
        $this->assertSame('2026-09-03', $settings->localDate($at));

        $settings->set('timezone', 'America/Los_Angeles');
        $this->assertSame('2026-09-02', $settings->localDate($at));
    }
}
