<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        // Super Admin holds every permission (Req 1.3). Returning null for everyone else
        // lets normal permission checks decide.
        Gate::before(fn ($user) => $user->hasRole(config('roles.super_admin')) ? true : null);
    }
}
