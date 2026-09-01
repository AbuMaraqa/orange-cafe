<?php

use App\Providers\NativeAppServiceProvider;

return [
    /**
     * The version of your app.
     * It is used to determine if the app needs to be updated.
     * Increment this value every time you release a new version of your app.
     */
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),

    /**
     * The ID of your application. This should be a unique identifier
     * usually in the form of a reverse domain name.
     * For example: com.nativephp.app
     */
    'app_id' => env('NATIVEPHP_APP_ID', 'ps.befoundonline.orangecafe'),

    /**
     * If your application allows deep linking, you can specify the scheme
     * to use here. This is the scheme that will be used to open your
     * application from within other applications.
     * For example: "nativephp"
     *
     * This would allow you to open your application using a URL like:
     * nativephp://some/path
     */
    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    /**
     * The author of your application.
     */
    'author' => env('NATIVEPHP_APP_AUTHOR'),

    /**
     * The copyright notice for your application.
     */
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    /**
     * The description of your application.
     */
    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'Orange Cafe — restaurant server'),

    /**
     * The Website of your application.
     */
    'website' => env('NATIVEPHP_APP_WEBSITE', 'https://befoundonline.ps'),

    /**
     * The default service provider for your application. This provider
     * takes care of bootstrapping your application and configuring
     * any global hotkeys, menus, windows, etc.
     */
    'provider' => NativeAppServiceProvider::class,

    /**
     * A list of environment keys that should be removed from the
     * .env file when the application is bundled for production.
     * You may use wildcards to match multiple keys.
     */
    'cleanup_env_keys' => [
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        'ZEPHPYR_*',

        // NativePHP's stock list has a blanket '*_SECRET' here. It is
        // removed deliberately: it matches REVERB_APP_SECRET, and the
        // packaged app *is* the Reverb server — the same .env has to carry
        // the secret for both the server that verifies signatures and the
        // Laravel process that signs with it. Stripping it ships an app
        // whose live updates are running on a null shared secret.
        //
        // It stays out of harm's way because it never leaves the machine:
        // Reverb here only listens on the restaurant's own LAN, and the
        // value is regenerated per deployment (.env.production.example).
        //
        // Credentials for the one-off MySQL import belong to the developer's
        // machine and have no meaning on a customer's, so those do go.
        'IMPORT_DB_PASSWORD',
        'IMPORT_DB_USERNAME',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
        'NATIVEPHP_AZURE_PUBLISHER_NAME',
        'NATIVEPHP_AZURE_ENDPOINT',
        'NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME',
        'NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME',
    ],

    /**
     * A list of files and folders that should be removed from the
     * final app before it is bundled for production.
     * You may use glob / wildcard patterns here.
     */
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',

        // Written by `npm run dev` (Vite) and holding a URL like
        // http://192.168.1.29:5174. While it exists, Laravel's @vite points
        // every page at that dev server instead of the compiled files in
        // public/build — so if a stale one is present at build time it gets
        // packaged, and every page on the customer's machine then fails with
        // ERR_CONNECTION_REFUSED against a dev server that doesn't exist
        // there. NativePHP's own exclude list covers storage/hot but not
        // this one.
        'public/hot',

        // The development machine's storage link, which on a build machine
        // running Laragon is always present — `storage:link` is part of
        // setting the project up. It is a junction pointing at
        // C:\...\orange-cafe\storage\app\public, a path that does not exist
        // on a customer's computer, and packaging tools resolve a junction by
        // copying what is behind it: the developer's own menu photography,
        // shipped inside the installer.
        //
        // NativeAppServiceProvider::linkPublicStorage() recreates it on the
        // customer's machine at boot, pointing at the relocated storage
        // directory — which is the only place it can be decided, since that
        // path is not known until the app runs there.
        'public/storage',

        // Any .env the developer keeps beside the production one while
        // building — .env.dev-backup is exactly what the documented build
        // procedure creates, and it holds the development machine's own
        // keys. NativePHP's stock exclude list covers none of these; only
        // the single active `.env` is meant to travel with the app.
        '.env.*',

        // Generated by app:first-run-setup; contains a plaintext admin
        // password. It lives under storage/ so it is writable on a packaged
        // install, which is outside NativePHP's storage/* exclusions.
        'storage/first-run-credentials.txt',

        // Nothing in the shipped app runs the test suite, and PHPUnit isn't
        // installed in the --no-dev vendor tree it would need.
        'tests',

        // The development machine's own logs, which have no business on a
        // customer's computer: they carry this machine's paths, its order
        // references, its printer names and its stack traces. They are also
        // the wrong file — the packaged app writes to the relocated storage
        // under %APPDATA%, so what ships here is never even read, it just
        // sits there. NativePHP's stock list covers storage/framework but
        // not storage/logs.
        'storage/logs',
    ],

    /**
     * The NativePHP updater configuration.
     */
    'updater' => [
        /**
         * Whether or not the updater is enabled. Please note that the
         * updater will only work when your application is bundled
         * for production.
         */
        // Off by default: there is no release feed to check, and with the
        // updater on, Electron logs a failed update check on every launch.
        // Turn it on together with a configured provider below.
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', false),

        /**
         * The updater provider to use.
         * Supported: "github", "s3", "spaces"
         */
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'spaces'),

        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO'),
                'owner' => env('GITHUB_OWNER'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],

            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],

            'spaces' => [
                'driver' => 'spaces',
                'key' => env('DO_SPACES_KEY_ID'),
                'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
                'name' => env('DO_SPACES_NAME'),
                'region' => env('DO_SPACES_REGION'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],
        ],
    ],

    /**
     * Deliberately empty — see 'orange_queue_worker' below, which
     * NativeAppServiceProvider starts instead.
     *
     * NativePHP reads this list from NativeServiceProvider::configureApp(),
     * which runs on *every* web request, and calls QueueWorker::up() for
     * each entry. Its Electron side returns the raw stored process object
     * when the alias already exists (childProcess.js: `if (getProcess(alias)
     * !== undefined) return state.processes[alias]`), and that object has no
     * `settings` key — so ChildProcess::fromRuntimeProcess() then dies on
     * "Trying to access array offset on null" and takes the whole request
     * with it. Leaving this empty makes that per-request path a no-op.
     */
    'queue_workers' => [],

    /**
     * Our own worker definition, started once at boot instead.
     *
     * Printing is the only thing on this queue (PrintOrderTicket /
     * PrintCashierReceipt), and a waiter standing at the pass should not
     * wait seconds for a kitchen ticket — hence the 1s poll rather than
     * NativePHP's default 3s.
     */
    'orange_queue_worker' => [
        'alias' => 'default',
        'queues' => ['default'],
        'memory_limit' => 256,
        'timeout' => 120,

        // How long the worker waits before looking again when it found
        // nothing. It is therefore the *fixed* delay between pressing print
        // and anything happening at all — pure dead time on the one action
        // where the cashier is standing in front of a customer waiting.
        //
        // NativePHP defaults to 3s; this was 1s, and is now 0.25s. Laravel
        // accepts a fraction here and passes it to usleep, so a quarter of a
        // second is a real quarter of a second. The cost is four wake-ups a
        // second against a local SQLite file with an index on the queue —
        // immeasurable next to the render it precedes.
        'sleep' => 0.25,
    ],

    /**
     * Whether a fresh install loads the real Orange Cafe catalog and staff
     * (LegacyOrangeDataSeeder) instead of coming up empty.
     *
     * Read through config() and not env() at the point of use, and that is
     * the whole reason this key exists. The packaged app runs `artisan
     * optimize` on every launch, which caches the config — and once
     * bootstrap/cache/config.php exists Laravel stops loading .env at all, so
     * every env() call outside a config file returns null. Reading the flag
     * directly from env() therefore always evaluated to false in the one
     * place it mattered: the customer's machine. Here it is resolved while
     * the cache is being built, so the value survives.
     *
     * Keep it false for a build handed to any other cafe — the data is one
     * specific business's menu, prices and staff logins.
     */
    'load_legacy_data' => env('FIRST_RUN_LOAD_LEGACY_DATA', false),

    /**
     * Define your own scripts to run before and after the build process.
     *
     * fetch-poppler.ps1 downloads (and caches) the pdftoppm binary into
     * bin/poppler/, which EscPosImageRenderer shells out to for
     * Arabic-capable thermal printing — without it in the bundle, every
     * ticket fails on the customer's machine. It lands in bin/ rather than
     * build/ on purpose: 'build' is in cleanup_exclude_files above, so
     * anything there is stripped out of the packaged app.
     *
     * NativePHP only prints an error and carries on when a prebuild command
     * fails, so this is not a hard gate — the control panel's "الطباعة
     * الحرارية" row reports the binary's absence at runtime as well.
     *
     * Icons are deliberately not generated here: this NativePHP release has
     * no `native:icon` command, and the build simply copies public/icon.png
     * and public/icon.ico as they are. They are committed, and regenerated
     * only when the mark changes (`php build/make-icons.php`).
     *
     * See docs/desktop-build.md.
     */
    'prebuild' => [
        'powershell -NoProfile -ExecutionPolicy Bypass -File build/fetch-poppler.ps1',
        'npm run build',
    ],

    'postbuild' => [
        // 'rm -rf public/build',
    ],

    /**
     * Custom PHP binary path.
     */
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),
];
