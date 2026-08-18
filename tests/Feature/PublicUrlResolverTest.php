<?php

namespace Tests\Feature;

use App\Services\Native\NativeEnvSettings;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\PublicUrlResolver;
use Tests\TestCase;

/**
 * These URLs get printed onto physical table signs. Getting one wrong means
 * reprinting every sign in the restaurant, so the rewriting is worth pinning
 * down precisely.
 */
class PublicUrlResolverTest extends TestCase
{
    private function resolver(array $env, ?string $lanIp = '192.168.1.50'): PublicUrlResolver
    {
        $settings = new class($env) extends NativeEnvSettings
        {
            public function __construct(private array $values) {}

            public function get(array $keys): array
            {
                return collect($keys)->mapWithKeys(fn ($k) => [$k => $this->values[$k] ?? null])->all();
            }
        };

        $network = new class($lanIp) extends NativeNetworkInfo
        {
            public function __construct(private ?string $ip) {}

            public function primaryLanIp(): ?string
            {
                return $this->ip;
            }
        };

        return new PublicUrlResolver($settings, $network);
    }

    public function test_an_explicit_public_url_wins(): void
    {
        $resolver = $this->resolver(['PUBLIC_BASE_URL' => 'https://menu.orangecafe.ps/']);

        $this->assertSame('https://menu.orangecafe.ps', $resolver->base());
    }

    public function test_it_falls_back_to_the_detected_lan_address_and_port(): void
    {
        $resolver = $this->resolver(['PUBLIC_BASE_URL' => '', 'LAN_SERVER_PORT' => '8001']);

        $this->assertSame('http://192.168.1.50:8001', $resolver->base());
    }

    public function test_it_defaults_the_port_when_none_is_configured(): void
    {
        $this->assertSame('http://192.168.1.50:8000', $this->resolver([])->base());
    }

    /**
     * The whole reason this class exists: the owner generates table signs
     * from the server machine itself, on localhost.
     */
    public function test_it_replaces_a_localhost_host_while_keeping_path_and_query(): void
    {
        $resolver = $this->resolver(['PUBLIC_BASE_URL' => 'http://192.168.1.50:8000']);

        $this->assertSame(
            'http://192.168.1.50:8000/en/menu?table_id=7&table_label=A1',
            $resolver->rewrite('http://localhost:8000/en/menu?table_id=7&table_label=A1'),
        );
    }

    public function test_it_leaves_a_value_it_cannot_parse_alone(): void
    {
        $resolver = $this->resolver(['PUBLIC_BASE_URL' => 'http://192.168.1.50:8000']);

        $this->assertSame('/en/menu', $resolver->rewrite('/en/menu'));
    }

    public function test_it_falls_back_to_app_url_when_no_network_is_reachable(): void
    {
        config(['app.url' => 'http://fallback.test/']);

        $this->assertSame('http://fallback.test', $this->resolver([], lanIp: null)->base());
    }
}
