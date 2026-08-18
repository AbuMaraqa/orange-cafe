<?php

namespace Tests\Feature;

use App\Services\Native\NativeEnvSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The control panel is the only interface the packaged desktop app has, and
 * it runs with no authentication at all — the window opens before any staff
 * account exists. Its access boundary is therefore the loopback check, and
 * that is what these guard.
 */
class NativeControlPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_renders_for_a_request_from_this_machine(): void
    {
        $this->get(route('native.panel'))->assertOk();
    }

    public function test_the_status_endpoint_reports_every_service(): void
    {
        $this->getJson(route('native.panel.status'))
            ->assertOk()
            ->assertJsonStructure([
                'database' => ['ok'],
                'reverb' => ['ok', 'process', 'host', 'port'],
                'queue' => ['process', 'pending', 'failed'],
                'scheduler' => ['process'],
                'lan_server' => ['ok', 'port'],
                'thermal' => ['ok', 'usb'],
                'printers' => ['active', 'online'],
            ]);
    }

    /**
     * The panel rewrites .env and can restart the server for the whole
     * restaurant, and the LAN server it is served behind is bound to
     * 0.0.0.0 — so a tablet, or a customer on the guest Wi-Fi, must not
     * reach any of it.
     */
    public function test_every_panel_route_is_refused_from_another_device_on_the_network(): void
    {
        $routes = [
            ['get', route('native.panel')],
            ['get', route('native.panel.status')],
            ['post', route('native.panel.update')],
            ['post', route('native.panel.restart')],
            ['post', route('native.panel.credentials.dismiss')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
                ->{$method}($url)
                ->assertForbidden();
        }
    }

    /**
     * Saving must not silently drop a key, because nothing re-reads .env
     * until the app is restarted — a lost value only surfaces as a service
     * failing to come back up.
     */
    public function test_saving_settings_writes_every_key_to_the_env_file(): void
    {
        $envPath = base_path('.env');
        $original = file_get_contents($envPath);

        try {
            $this->postJson(route('native.panel.update'), [
                'reverb_host' => '192.168.1.50',
                'reverb_port' => 9001,
                'lan_server_port' => 8001,
                'public_base_url' => 'http://192.168.1.50:8001/',
            ])->assertOk();

            $written = app(NativeEnvSettings::class)->get([
                'REVERB_HOST', 'REVERB_PORT', 'LAN_SERVER_PORT', 'PUBLIC_BASE_URL',
                'VITE_REVERB_HOST', 'VITE_REVERB_PORT',
            ]);

            $this->assertSame('192.168.1.50', $written['REVERB_HOST']);
            $this->assertSame('9001', $written['REVERB_PORT']);
            $this->assertSame('8001', $written['LAN_SERVER_PORT']);
            $this->assertSame('192.168.1.50', $written['VITE_REVERB_HOST']);
            $this->assertSame('9001', $written['VITE_REVERB_PORT']);
            // Trailing slash stripped, so it concatenates cleanly with paths.
            $this->assertSame('http://192.168.1.50:8001', $written['PUBLIC_BASE_URL']);
        } finally {
            file_put_contents($envPath, $original);
        }
    }

    public function test_an_invalid_public_url_is_rejected_rather_than_written(): void
    {
        $original = file_get_contents(base_path('.env'));

        $this->postJson(route('native.panel.update'), [
            'reverb_host' => '127.0.0.1',
            'reverb_port' => 8080,
            'lan_server_port' => 8000,
            'public_base_url' => 'not a url',
        ])->assertStatus(422);

        $this->assertSame($original, file_get_contents(base_path('.env')));
    }
}
