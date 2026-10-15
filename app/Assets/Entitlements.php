<?php

namespace App\Assets;

use App\Models\User;

/**
 * Does this customer own access to this asset? Commerce (orders, entitlements) arrives
 * in Phase 4; until then NoEntitlements answers "no" for everyone.
 */
interface Entitlements
{
    public function allows(User $user, AssetRef $asset): bool;
}
