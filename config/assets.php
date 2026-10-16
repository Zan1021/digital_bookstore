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

    // Lifetime of a signed staff-only working-file link (review images, upload previews):
    // long enough for a review page left open, still bound to the staff member.
    'staff_file_minutes' => (int) env('ASSETS_STAFF_FILE_MINUTES', 60),

    // Largest PDF the intake screen accepts, in kilobytes (keep config/livewire.php's
    // temporary upload rule and PHP's upload_max_filesize at least this large).
    'max_upload_kb' => (int) env('ASSETS_MAX_UPLOAD_KB', 102400),

];
