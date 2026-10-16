<?php

namespace App\Providers;

use App\Auth\StaffAuthorization;
use App\Http\Middleware\EnsureStaffPermission;
use App\Livewire\Attributes\Can;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Customer entitlements arrive with commerce (Phase 4); until then nobody owns a book.
        $this->app->bind(\App\Assets\Entitlements::class, \App\Assets\NoEntitlements::class);

        // AI provider layer (tasks 7.1–7.2): every interface resolves to the metered decorator
        // (ledger, cache, spending limits) around the real provider.
        $this->app->bind(\App\Ai\MeteredProvider::class, fn ($app) => new \App\Ai\MeteredProvider(
            $app->make(\App\Ai\OpenAi\OpenAiTextProvider::class),
            $app->make(\App\Ai\OpenAi\OpenAiTextProvider::class),
            $app->make(\App\Ai\OpenAi\OpenAiImageEditProvider::class),
            $app->make(\App\Ai\ElevenLabs\ElevenLabsTtsProvider::class),
        ));
        foreach ([\App\Ai\Contracts\TranslationProvider::class, \App\Ai\Contracts\VisionProvider::class,
            \App\Ai\Contracts\ImageEditProvider::class, \App\Ai\Contracts\TtsProvider::class] as $contract) {
            $this->app->bind($contract, \App\Ai\MeteredProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin holds every permission (Req 1.3). Returning null for everyone else
        // lets normal permission checks decide.
        Gate::before(fn ($user) => $user->hasRole(config('roles.super_admin')) ? true : null);

        // Re-check staff permissions on Livewire update requests, not only on page load.
        Livewire::addPersistentMiddleware([EnsureStaffPermission::class]);

        // Fail closed: an admin component action without a #[Can] declaration is refused.
        // Livewire's own magic actions ($refresh, $set, ...) and its trait plumbing
        // (file-upload handshakes) are not app actions and pass through.
        \Livewire\on('call', function ($component, string $method) {
            if (str_starts_with($component::class, 'App\\Livewire\\Admin\\')
                && ! str_starts_with($method, '$')
                && ! Can::isFrameworkMethod($component, $method)
                && ! Can::declaredOn($component, $method)) {
                StaffAuthorization::deny([
                    'component' => $component::class,
                    'action' => $method,
                    'reason' => 'action declares no #[Can] permission',
                ]);
            }
        });
    }
}
