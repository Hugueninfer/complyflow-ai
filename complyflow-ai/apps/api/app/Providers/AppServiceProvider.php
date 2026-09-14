<?php

namespace App\Providers;

use App\Services\Analysis\PersistProcessorResult;
use App\Services\Processor\ResultPersister;
use App\Support\CurrentOrganization;
use App\Support\PublicAccessLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->bind(ResultPersister::class, PersistProcessorResult::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-login', fn (Request $request) => [
            Limit::perMinute(5)->by('identity:'.PublicAccessLimits::identity($request)),
            Limit::perMinute(20)->by('session:'.PublicAccessLimits::session($request)),
        ]);
        RateLimiter::for('public-register', fn (Request $request) => [
            Limit::perMinute(10)->by('identity:'.PublicAccessLimits::identity($request)),
            Limit::perMinute(10)->by('session:'.PublicAccessLimits::session($request)),
        ]);
        RateLimiter::for('public-demo', fn (Request $request) => [
            Limit::perMinute(10)->by('session:'.PublicAccessLimits::session($request)),
            // Bound cookie cycling without turning one visitor's ten requests into a global lockout.
            Limit::perMinute(60)->by('aggregate'),
        ]);
    }
}
