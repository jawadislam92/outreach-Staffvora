<?php

namespace App\Providers;

use App\Services\Ai\ClaudeDraftGenerator;
use App\Services\Ai\DraftGenerator;
use App\Services\Sending\ConnectorFactory;
use App\Services\Sending\DefaultConnectorFactory;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(ConnectorFactory::class, DefaultConnectorFactory::class);
        $this->app->singleton(DraftGenerator::class, ClaudeDraftGenerator::class);
    }

    public function boot(): void
    {
        // Lazy-load and discarded-attribute guards only: the missing-attribute
        // guard false-positives on factory instances used with actingAs().
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Vite::prefetch(concurrency: 3);

        // IP-level login throttle on top of LoginRequest's per-email lockout.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
