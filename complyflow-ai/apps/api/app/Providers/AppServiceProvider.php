<?php

namespace App\Providers;

use App\Services\Processor\ResultPersister;
use App\Services\Processor\UnavailableResultPersister;
use App\Support\CurrentOrganization;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->bind(ResultPersister::class, UnavailableResultPersister::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
