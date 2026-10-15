<?php

namespace App\Assets;

use App\Models\User;

/** Placeholder until orders and entitlements exist (Phase 4): nobody is entitled. */
class NoEntitlements implements Entitlements
{
    public function allows(User $user, AssetRef $asset): bool
    {
        return false;
    }
}
