<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // Even a redirect goes through HandleInertiaRequests::share(), which
    // reads the restaurant settings — so these need a schema, in-memory
    // though it is.
    use RefreshDatabase;

    /**
     * "/" is the customer entry point, not the Breeze welcome page: scanning
     * a table QR lands on the menu directly, and someone typing the bare
     * address should get there too rather than a scaffold screen.
     *
     * (The locale-prefix hop that also happens in a real request is disabled
     * in tests — see Tests\TestCase for why.)
     */
    public function test_the_root_url_redirects_to_the_customer_menu(): void
    {
        $this->get('/')->assertRedirect(route('menu.index'));
    }

    /**
     * The health endpoint configured in bootstrap/app.php. Unlike "/", it is
     * not localized and does not touch the database — which is exactly why
     * it is the right smoke test that the framework boots at all.
     */
    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
