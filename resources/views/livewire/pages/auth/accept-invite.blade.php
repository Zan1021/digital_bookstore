<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/*
 * Staff invitation (task 2.4): the invitee sets their password with the 7-day token from
 * the 'invites' broker, is signed in and lands in the admin area.
 */
new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    public function acceptInvite(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $accepted = null;
        $status = Password::broker('invites')->reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use (&$accepted) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                    'invited_at' => null,
                ])->save();

                event(new PasswordReset($user));
                $accepted = $user;
            }
        );

        if ($status != Password::PASSWORD_RESET || $accepted === null || $accepted->isDeactivated()) {
            $this->addError('email', $status == Password::PASSWORD_RESET
                ? __('This account has been deactivated.')
                : __('This invitation link is invalid or has expired. Ask for a new invitation.'));

            return;
        }

        Auth::login($accepted);
        Session::regenerate();

        $this->redirectRoute($accepted->can('admin.access') ? 'admin.dashboard' : 'dashboard');
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Welcome! Choose a password to finish setting up your account.') }}
    </div>

    <form wire:submit="acceptInvite">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                          type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Set password and sign in') }}
            </x-primary-button>
        </div>
    </form>
</div>
