<?php

/*
|--------------------------------------------------------------------------
| Protected asset delivery (Req 20.1, 20.2; design §21)
|--------------------------------------------------------------------------
|
| Book files live on a private disk and are only handed out by AssetController:
| it checks staff permission, entitlement or preview rights, then redirects to a
| signed URL that expires after `signed_url_minutes` and only works for the user it
| was issued to.
|
*/

return [

    // Disk holding protected assets ('private' locally; an S3-compatible disk later).
    'disk' => env('ASSETS_DISK', 'private'),

    // Lifetime of a signed asset URL.
    'signed_url_minutes' => (int) env('ASSETS_SIGNED_URL_MINUTES', 5),

];
