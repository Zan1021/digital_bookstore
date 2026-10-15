<?php

namespace App\Livewire\Admin\Testing;

use App\Livewire\Attributes\Can;
use Livewire\Component;

/**
 * Test fixture (loaded by AdminActionPermissionsTest, not autoloaded): an admin component
 * with one action that forgot its #[Can] declaration, to prove the guard fails closed.
 */
class UndeclaredActionComponent extends Component
{
    public bool $ran = false;

    public function forgotToDeclare(): void
    {
        $this->ran = true;
    }

    #[Can('admin.access')]
    public function declared(): void
    {
        $this->ran = true;
    }

    public function render()
    {
        return '<div></div>';
    }
}
