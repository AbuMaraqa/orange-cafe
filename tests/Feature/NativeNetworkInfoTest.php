<?php

namespace Tests\Feature;

use App\Services\Native\NativeNetworkInfo;
use Tests\TestCase;

class NativeNetworkInfoTest extends TestCase
{
    private function listenOnLoopback(): array
    {
        // Port 0 lets the OS pick a free one, so the test can never collide
        // with something already running on this machine.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        $this->assertNotFalse($server, "could not open a test listener: {$errstr}");

        $port = (int) explode(':', stream_socket_get_name($server, false))[1];

        return [$server, $port];
    }

    /**
     * The regression this exists for: passing "localhost" straight to
     * fsockopen on Windows dials ::1, while every server this app starts
     * (Reverb, `artisan serve`) binds 0.0.0.0 — IPv4 only. The probe timed
     * out against a healthy server and the control panel showed it as down.
     * REVERB_HOST=localhost is the shipped default, so this was the normal
     * case rather than an edge one.
     */
    public function test_it_finds_an_ipv4_listener_through_the_hostname_localhost(): void
    {
        [$server, $port] = $this->listenOnLoopback();

        try {
            $network = new NativeNetworkInfo;

            $this->assertTrue($network->portOpen('127.0.0.1', $port), 'IPv4 literal should connect');
            $this->assertTrue($network->portOpen('localhost', $port), 'hostname should resolve to the IPv4 listener');
        } finally {
            fclose($server);
        }
    }

    public function test_a_closed_port_is_reported_closed(): void
    {
        [$server, $port] = $this->listenOnLoopback();
        fclose($server);

        $network = new NativeNetworkInfo;

        // Short timeout: this must not fall back to waiting out a full
        // connect timeout per candidate address.
        $this->assertFalse($network->portOpen('localhost', $port, 0.5));
        $this->assertFalse($network->portOpen('127.0.0.1', $port, 0.5));
    }

    public function test_an_unresolvable_host_is_reported_closed_rather_than_throwing(): void
    {
        $network = new NativeNetworkInfo;

        $this->assertFalse($network->portOpen('no-such-host.invalid', 8080, 0.5));
    }

    /**
     * The tray menu and the panel's "open from another device" links are
     * built from this; a loopback or link-local address there is worse than
     * no address at all, because it looks like it should work.
     */
    public function test_lan_addresses_exclude_loopback_and_link_local(): void
    {
        foreach ((new NativeNetworkInfo)->lanIpAddresses() as $ip) {
            $this->assertNotSame('127.0.0.1', $ip);
            $this->assertStringStartsNotWith('169.254.', $ip);
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
        }
    }
}
