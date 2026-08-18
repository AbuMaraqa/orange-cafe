<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every route in routes/web.php is registered under a prefix produced by
     * LaravelLocalization::setLocale(), which reads the *current request's*
     * first URL segment. In a real server that resolves per request: a hit on
     * "/login" registers the routes unprefixed, matches, and the redirect
     * middleware bounces the browser to "/en/login" — a second request, in
     * which the routes are registered again, this time under "/en".
     *
     * A test process boots the application once and registers the routes
     * once. The redirect therefore has nowhere to land: the routes in memory
     * have no "/en" prefix, so following it 404s, and not following it turns
     * every "assert this page renders" into a 302.
     *
     * Disabling the two redirect middlewares makes the test process behave
     * like the second request of that pair, which is the one the assertions
     * are actually about. Locale *resolution* is untouched — the `localize`
     * and `sync.locale` middlewares still run, so anything that depends on
     * the active locale is still exercised. A test specifically about the
     * redirect can opt back in with withMiddleware().
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);
    }
}
