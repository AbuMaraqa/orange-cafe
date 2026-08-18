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
        // Deliberately null by default — App\Services\Printing\PopplerBinary
        // does the resolving (bundled copy under bin/poppler first, then
        // PATH), because the packaged app ships a config cache built on the
        // developer's machine and any path baked in here would point at a
        // directory that doesn't exist on the customer's. Set this only to
        // force a specific binary.
        'pdftoppm_path' => env('POPPLER_PDFTOPPM_PATH'),
    ],

    'libusb' => [
        // FFI::cdef's library argument: a bare soname resolves via the
        // linker's normal search path on Linux hosts (where libusb-1.0-0
        // ships the versioned .so); override with a full path to a
        // platform-specific binary (e.g. libusb-1.0.dll on Windows).
        //
        // Note: direct-USB printing needs PHP's FFI extension, which the
        // NativePHP runtime does not bundle — on the packaged Windows app
        // only network printers work. See UsbPrinterTransport.
        'path' => env('LIBUSB_PATH', PHP_OS_FAMILY === 'Windows' ? 'libusb-1.0.dll' : 'libusb-1.0.so.0'),
    ],

];
