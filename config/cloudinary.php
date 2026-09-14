<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * Section 12. Uploads go browser → Cloudinary directly; the API only signs the
 * request and registers the result. Nothing large ever transits PHP, which
 * matters because shared hosting caps upload_max_filesize and
 * max_execution_time and we do not control either (doc 16.6).
 */
return [
    'cloud_name' => Env::get('CLOUDINARY_CLOUD_NAME'),
    'api_key'    => Env::get('CLOUDINARY_API_KEY'),
    'api_secret' => Env::get('CLOUDINARY_API_SECRET'),

    'folder_prefix' => 'rajdhani',

    'limits' => [
        'image' => [
            'max_bytes' => 5 * 1024 * 1024,
            'mime'      => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
        ],
        'document' => [
            'max_bytes' => 20 * 1024 * 1024,
            'mime'      => ['application/pdf'],
        ],
    ],

    'delivery' => ['f_auto', 'q_auto'],
];
