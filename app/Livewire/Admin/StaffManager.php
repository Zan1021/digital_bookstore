<?php

namespace App\Livewire\Admin;

use App\Livewire\Attributes\Can;
use App\Models\User;
use App\Services\Staff\StaffManagement;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Staff management (task 2.4, Req 1.5): invite staff, change their role, deactivate and
 * reactivate them. Super Admin only (staff.manage); the rules live in StaffManagement.
 */
class StaffManager extends Component
{
    public string $name = '';
    public string $email = '';
    public string $role = '';

    public ?string $notice = null;

    #[Can('staff.manage')]
    public function invite(StaffManagement $staff): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        $user = $staff->invite(auth()->user(), $this->name, $this->email, $this->role);

        $this->notice = $user->isPendingInvite()
            ? __('Invitation sent to :email.', ['email' => $user->email])
            : __(':email already had an account and is now :role.', ['email' => $user->email, 'role' => $this->role]);
        $this->reset('name', 'email', 'role');
    }

    #[Can('staff.manage')]
    public function changeRole(StaffManagement $staff, int $userId, string $role): void
    {
        $this->attempt(fn () => $staff->changeRole(auth()->user(), $this->staffMember($userId), $role),
            __('Role updated.'));
    }

    #[Can('staff.manage')]
    public function deactivate(StaffManagement $staff, int $userId): void
    {
        $this->attempt(fn () => $staff->deactivate(auth()->user(), $this->staffMember($userId)),
            __('Account deactivated. They are signed out on their next request.'));
    }

    #[Can('staff.manage')]
    public function reactivate(StaffManagement $staff, int $userId): void
    {
        $this->attempt(fn () => $staff->reactivate(auth()->user(), $this->staffMember($userId)),
            __('Account reactivated.'));
    }

    #[Can('staff.manage')]
    public function resendInvite(StaffManagement $staff, int $userId): void
    {
        $this->attempt(fn () => $staff->resendInvite(auth()->user(), $this->staffMember($userId)),
            __('Invitation sent again.'));
    }

    /** Only users who hold a staff role can be managed here (never customers). */
    private function staffMember(int $userId): User
    {
        return User::whereHas('roles')->findOrFail($userId);
    }

    private function attempt(callable $change, string $success): void
    {
        $this->resetErrorBag();
        try {
            $change();
            $this->notice = $success;
        } catch (ValidationException $e) {
            $this->notice = null;
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function render(StaffManagement $staff)
    {
        return view('livewire.admin.staff-manager', [
            'staff' => User::whereHas('roles')->with('roles')->orderBy('name')->get(),
            'roles' => $staff->roles(),
        ])->layout('layouts.admin');
    }
}
