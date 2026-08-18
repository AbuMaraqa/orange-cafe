<?php

namespace App\Services\Native;

/**
 * The address customers' phones must use — the one baked into every printed
 * table QR code.
 *
 * Ordinary route(..., absolute: true) calls build on the *current request's*
 * host, which is right for links a browser follows and wrong for a QR code:
 * the owner generates table signs from the server machine itself, usually at
 * http://localhost:8000, and a sign encoding "localhost" sends every scanning
 * phone to itself. So QR URLs get their scheme/host/port rewritten to this
 * value instead.
 *
 * Read from .env through NativeEnvSettings rather than config()/env(),
 * because the packaged app ships a config cache built on the developer's
 * machine — env() returns null once that cache exists, and config() would
 * hand back the developer's value. See NativeEnvSettings.
 */
class PublicUrlResolver
{
    private ?string $memoized = null;

    private bool $resolved = false;

    public function __construct(
        private NativeEnvSettings $env,
        private NativeNetworkInfo $network,
    ) {}

    /**
     * Base URL with no trailing slash, e.g. "http://192.168.1.50:8000".
     */
    public function base(): string
    {
        if ($this->resolved) {
            return $this->memoized;
        }

        $this->resolved = true;

        return $this->memoized = rtrim($this->configured() ?? $this->detected(), '/');
    }

    /**
     * Swap the scheme/host/port of an already-built application URL for the
     * public one, leaving path and query exactly as generated. Returns the
     * input unchanged if it isn't parseable as an absolute URL.
     */
    public function rewrite(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $tail = ($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return $this->base().$tail;
    }

    /**
     * An explicitly configured value always wins — a cafe on a static LAN
     * IP, or one exposing the menu through a real domain.
     */
    private function configured(): ?string
    {
        $value = $this->env->get(['PUBLIC_BASE_URL'])['PUBLIC_BASE_URL'] ?? null;
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * This machine's LAN address on the port the LAN server listens on —
     * correct on the overwhelmingly common setup (DHCP reservation or a
     * stable lease) and, unlike "localhost", at least reachable from a phone
     * when it isn't.
     */
    private function detected(): string
    {
        $port = (int) ($this->env->get(['LAN_SERVER_PORT'])['LAN_SERVER_PORT'] ?: 8000);
        $ip = $this->network->primaryLanIp();

        return $ip !== null
            ? "http://{$ip}:{$port}"
            : rtrim((string) config('app.url'), '/');
    }
}
