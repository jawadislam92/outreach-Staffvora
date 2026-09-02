<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Operational settings the admin edits in-app (secrets stay in .env).
 * Cached as one map; every write clears the cache and audit-logs the change.
 */
class Settings
{
    private const CACHE_KEY = 'settings.all';

    public const DEFAULTS = [
        'timezone' => 'Asia/Karachi',
        'default_daily_cap' => 30,
        'min_gap_warning_seconds' => 120,
        'ai_model' => 'claude-opus-5',
        'ai_effort' => 'medium',
        'max_attachment_mb' => 10,
    ];

    public const EFFORTS = ['low', 'medium', 'high'];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, function () {
            return Setting::query()->pluck('value', 'key')->all();
        });

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?User $actor = null): void
    {
        $before = $this->get($key);

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);

        if ($before !== $value) {
            AuditLog::record('settings.update', $actor, 'setting', $key, [$key => $before], [$key => $value]);
        }
    }

    public function timezone(): string
    {
        $tz = (string) $this->get('timezone');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /** Business-day date (Y-m-d) for an instant, in the configured timezone. */
    public function localDate(?CarbonInterface $at = null): string
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return $at->setTimezone($this->timezone())->toDateString();
    }

    public function today(): string
    {
        return $this->localDate();
    }

    public function defaultDailyCap(): int
    {
        return max(0, (int) $this->get('default_daily_cap'));
    }

    public function minGapWarningSeconds(): int
    {
        return max(0, (int) $this->get('min_gap_warning_seconds'));
    }

    public function aiModel(): string
    {
        $model = (string) $this->get('ai_model');

        return array_key_exists($model, config('outreach.anthropic.models', [])) ? $model : self::DEFAULTS['ai_model'];
    }

    public function aiEffort(): string
    {
        $effort = (string) $this->get('ai_effort');

        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULTS['ai_effort'];
    }

    public function maxAttachmentBytes(): int
    {
        return max(1, (int) $this->get('max_attachment_mb')) * 1024 * 1024;
    }
}
