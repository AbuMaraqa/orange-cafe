<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WifiQrCode;
use App\Settings\RestaurantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Wi-Fi sign is printed from the restaurant settings and belongs to the
 * venue, not to a table — changing the password should mean reprinting one
 * page, not every table sign in the room.
 */
class WifiQrCodeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        return tap(User::factory()->create())->assignRole('admin');
    }

    private function configureWifi(?string $ssid, ?string $password = 'secret'): void
    {
        $settings = app(RestaurantSettings::class);
        $settings->wifi_ssid = $ssid;
        $settings->wifi_password = $password;
        $settings->save();
    }

    public function test_it_prints_a_standalone_wifi_sign(): void
    {
        $this->configureWifi('Orange-Cafe');

        $response = $this->actingAs($this->admin())->get(route('admin.settings.wifi-qr'));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * Without an SSID there is no network to encode, so the route must not
     * hand back a QR code for an empty string.
     */
    public function test_it_refuses_to_print_when_no_network_is_configured(): void
    {
        $this->configureWifi(null);

        $this->actingAs($this->admin())
            ->get(route('admin.settings.wifi-qr'))
            ->assertNotFound();
    }

    public function test_only_an_admin_can_print_it(): void
    {
        $this->configureWifi('Orange-Cafe');

        $this->get(route('admin.settings.wifi-qr'))->assertRedirect();
    }

    /**
     * `\ ; , : "` carry meaning inside the WIFI: payload. An unescaped
     * semicolon in a password terminates the field early and silently hands
     * the phone the wrong credentials.
     */
    public function test_separators_in_the_credentials_are_escaped(): void
    {
        $settings = app(RestaurantSettings::class);
        $settings->wifi_ssid = 'Cafe;Guest';
        $settings->wifi_password = 'p@ss;word';

        $payload = app(WifiQrCode::class)->payload($settings);

        $this->assertSame('WIFI:T:WPA;S:Cafe\;Guest;P:p@ss\;word;;', $payload);
    }

    public function test_a_passwordless_network_is_encoded_as_nopass(): void
    {
        $settings = app(RestaurantSettings::class);
        $settings->wifi_ssid = 'Cafe-Guest';
        $settings->wifi_password = '';

        // T:WPA with an empty password makes some phones prompt for one that
        // does not exist.
        $this->assertSame('WIFI:T:nopass;S:Cafe-Guest;P:;;', app(WifiQrCode::class)->payload($settings));
    }

    /**
     * The table sign must no longer carry the Wi-Fi code at all.
     */
    public function test_the_table_sign_no_longer_embeds_the_wifi_code(): void
    {
        $blade = file_get_contents(resource_path('views/pdf/table-qr.blade.php'));

        $this->assertStringNotContainsString('wifiQrCode', $blade);
    }
}
