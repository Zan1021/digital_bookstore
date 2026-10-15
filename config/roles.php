<?php

/*
|--------------------------------------------------------------------------
| Staff roles and their default permissions (Req 1.3, PB §74)
|--------------------------------------------------------------------------
|
| RolesAndPermissionsSeeder syncs this matrix into the database, so it is the
| starting point; the staff screen (task 2.4) makes it configurable per role.
| Permissions follow the admin areas of design.md §17.
|
| The Super Admin role is granted every permission implicitly (Gate::before in
| AppServiceProvider), so it never needs listing here. Customers have no role.
|
*/

return [

    'super_admin' => 'Super Admin',

    // First Super Admin, created by SuperAdminSeeder (no credentials in code).
    'seed_super_admin' => [
        'email'    => env('SUPER_ADMIN_EMAIL'),
        'name'     => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    'permissions' => [
        'admin.access'        => 'Reach the admin area',
        'books.view'          => 'View books and their editions',
        'books.manage'        => 'Upload, edit and delete books, pages and metadata',
        'catalogue.manage'    => 'Manage series, categories, tags and collections',
        'translations.manage' => 'Start and edit translations (spends AI budget)',
        'translations.review' => 'Review and approve a language edition',
        'layout.review'       => 'Review page layout and resolve flagged pages',
        'narration.manage'    => 'Generate and edit narration (spends AI budget)',
        'narration.review'    => 'Review and approve narration',
        'editions.publish'    => 'Publish and unpublish editions',
        'gates.override'      => 'Override a failed hard gate with a reason (Req 7.3)',
        'store.manage'        => 'Manage products, pricing, promotions and store pages',
        'orders.view'         => 'View orders',
        'orders.manage'       => 'Change orders, refunds and cancellations',
        'fulfilment.manage'   => 'Manage physical stock and shipping',
        'customers.view'      => 'View customer accounts',
        'customers.manage'    => 'Change customer access and devices',
        'reviews.moderate'    => 'Approve, reject and hide customer reviews',
        'settings.manage'     => 'Manage fonts, languages, templates and AI limits',
        'staff.manage'        => 'Invite, deactivate and change roles of staff',
        'audit.view'          => 'View the audit log',
    ],

    'roles' => [
        'Content Manager' => [
            'admin.access', 'books.view', 'books.manage', 'catalogue.manage',
            'translations.manage', 'narration.manage', 'editions.publish', 'audit.view',
        ],
        'Language Reviewer' => [
            'admin.access', 'books.view', 'translations.review',
        ],
        'Layout Reviewer' => [
            'admin.access', 'books.view', 'layout.review',
        ],
        'Narration Reviewer' => [
            'admin.access', 'books.view', 'narration.review',
        ],
        'Store Manager' => [
            'admin.access', 'books.view', 'store.manage', 'orders.view', 'orders.manage',
            'customers.view', 'reviews.moderate',
        ],
        'Fulfilment Manager' => [
            'admin.access', 'orders.view', 'fulfilment.manage',
        ],
        'Support' => [
            'admin.access', 'books.view', 'orders.view', 'customers.view', 'customers.manage',
        ],
    ],

];
