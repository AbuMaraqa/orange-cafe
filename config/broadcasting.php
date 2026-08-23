<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',

                // Reverb runs on this same machine, so a healthy publish
                // completes in single-digit milliseconds. Left unset, the
                // Pusher client waits its default ~2s just to connect and up
                // to 30s overall — and every one of this app's events is
                // ShouldBroadcastNow, meaning it publishes *inside* the web
                // request. So a Reverb that is down or still starting turned
                // each "add item" tap into a two-second stall for the waiter,
                // for a notification that is only ever a nice-to-have
                // (BroadcastsSafely already swallows the failure).
                //
                // Three seconds, with the connect timeout below doing the
                // work the old single second was doing.
                //
                // One second was too tight, and the failure was specific: a
                // warm publish measures ~560ms on this machine, so the
                // margin was under 2x, and the first broadcast after the app
                // starts — while Reverb is still coming up — went over it.
                // The log has it at 10:30:52, thirty-five seconds after a
                // launch. A table ordering in that window was never
                // announced to anyone.
                //
                // Raising this alone would have reinstated the stall the
                // single second existed to prevent, so it is split: see
                // connect_timeout in client_options.
                'timeout' => 3,
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
                // Laravel calls its own Reverb HTTP API over TLS to publish events;
                // point Guzzle at the local self-signed CA instead of disabling
                // verification outright.
                'verify' => env('REVERB_TLS_CA', true),

                // The ceiling on what a *dead* Reverb costs the person
                // holding the tablet, which is the thing worth bounding
                // tightly. Reverb is on this machine: if it is not running,
                // the connection is refused in microseconds and this never
                // comes near its limit. `timeout` above then only applies
                // once something has answered — a Reverb that is alive but
                // still warming up gets its three seconds, while a Reverb
                // that is gone still fails instantly.
                'connect_timeout' => 1,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
