<?php

namespace App\Assets;

use App\Models\User;

/**
 * Who may receive a protected asset (Req 20.2, design §21), checked in this order:
 *   staff    — signed-in staff with books.view (and the account active)
 *   entitled — a customer whose entitlement covers it (Entitlements)
 *   preview  — anyone, but only for a single-page asset whose page is one of the
 *              book's preview pages (book metadata `preview_pages`, none by default)
 * Anything else is refused.
 */
class AssetAccess
{
    public function __construct(private Entitlements $entitlements) {}

    /** @return 'staff'|'entitled'|'preview'|null the reason access is allowed, or null */
    public function reason(?User $user, AssetRef $asset): ?string
    {
        if ($user?->isDeactivated()) {
            return null;
        }
        if ($user && $user->can('admin.access') && $user->can('books.view')) {
            return 'staff';
        }
        if ($user && $this->entitlements->allows($user, $asset)) {
            return 'entitled';
        }
        if ($asset->isPageScoped() && in_array($asset->page, $this->previewPages($asset), true)) {
            return 'preview';
        }

        return null;
    }

    /** @return list<int> */
    private function previewPages(AssetRef $asset): array
    {
        $pages = $asset->book->metadata['preview_pages'] ?? [];

        return array_values(array_map('intval', is_array($pages) ? $pages : []));
    }
}
