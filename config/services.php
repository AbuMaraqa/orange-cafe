<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'poppler' => [
        // Bare command name resolves via PATH on hosts where poppler-utils
        // is installed system-wide (the deployment target); override with
        // a full path where it isn't (e.g. no package manager install, as
        // on Windows dev boxes).
        'pdftoppm_path' => env('POPPLER_PDFTOPPM_PATH', 'pdftoppm'),
    ],

    'libusb' => [
        // FFI::cdef's library argument: a bare soname resolves via the
        // linker's normal search path on the deployment target (Linux,
        // where libusb-1.0-0 ships the versioned .so); override with a
        // full path to a platform-specific binary (e.g. the .dll on
        // Windows dev boxes, which don't have libusb installed as a
        // system package).
        'path' => env('LIBUSB_PATH', 'libusb-1.0.so.0'),
    ],

];
