<?php

namespace App\Providers;

use App\Services\Channel\ChannelRegistry;
use App\Services\Flow\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registry singleton: la scansione filesystem viene fatta una sola
        // volta per richiesta.
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ChannelRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
