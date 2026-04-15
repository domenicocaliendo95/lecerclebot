<?php

namespace App\Providers;

use App\Services\Flow\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Il registry dei moduli Flow è un singleton: la scansione della
        // directory Modules viene fatta una sola volta per richiesta.
        $this->app->singleton(ModuleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
