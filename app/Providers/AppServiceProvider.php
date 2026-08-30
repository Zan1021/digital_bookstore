<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Classification/description LLM client. Defaults to a no-cost, no-network
        // implementation; swap this binding for a real provider in production. Keeping
        // it behind the interface means AI cost/config stays out of the domain code.
        $this->app->bind(
            \App\Services\Classification\LlmClient::class,
            \App\Services\Classification\NullLlmClient::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
