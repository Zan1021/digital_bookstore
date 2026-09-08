<?php

return [
    'temporary_file_upload' => [
        'disk' => null,
        // Allow PDF temporary uploads up to 100 MB. Livewire's DEFAULT rule only permits
        // images/audio/video and caps at 12 MB, so selecting a PDF was silently rejected
        // (temp upload never happened -> updatedFiles() never fired -> "nothing happens").
        // 102400 KB = 100 MB, matching BookUpload::startProcessing()'s own validation and
        // within php upload_max_filesize/post_max_size (128M).
        'rules' => ['file', 'mimes:pdf', 'max:102400'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
            'pdf', // Allow PDF preview
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
