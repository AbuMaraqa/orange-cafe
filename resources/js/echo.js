import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/**
 * Everything Vite knows about Reverb is frozen into this bundle at build
 * time, which is wrong for an installation the server operator can
 * reconfigure afterwards. So each value is resolved at runtime instead, and
 * the compiled VITE_REVERB_* values are only the last resort.
 *
 * The meta tags are rendered per request by resources/views/app.blade.php
 * from the server's own config.
 */
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || null;

// The host is deliberately never taken from the server: it would have to
// guess which of its own addresses this particular client can reach. The
// address the page was loaded from is already the right answer for a waiter's
// tablet, the kitchen screen and a customer's phone alike. An explicitly
// configured, non-loopback build-time host still wins, for a hosted
// deployment served through a real domain.
const configuredHost = import.meta.env.VITE_REVERB_HOST;
const wsHost = (!configuredHost || configuredHost === 'localhost' || configuredHost === '127.0.0.1')
    ? window.location.hostname
    : configuredHost;

// The port and scheme, in contrast, are the server's to declare — it is the
// one that knows which port Reverb was actually started on.
const wsPort = Number(meta('reverb-port') ?? import.meta.env.VITE_REVERB_PORT ?? 80);
const scheme = meta('reverb-scheme') ?? import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const key = meta('reverb-key') ?? import.meta.env.VITE_REVERB_APP_KEY;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost,
    wsPort,
    wssPort: wsPort,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
