<?php

namespace Tests\Feature;

use App\Services\Native\LanReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reading that tells a dismissed firewall prompt apart from a healthy
 * server.
 *
 * The control panel probes 127.0.0.1 for the LAN port, which the machine can
 * always reach — so a server that is listening perfectly and blocked from
 * every tablet in the restaurant reports green. This records whether anything
 * other than this machine ever actually got through, which is the only
 * question worth asking and the only one with a trustworthy answer (see the
 * class docblock for why the firewall itself is not asked).
 */
class LanReachabilityTest extends TestCase
{
    // The two middleware cases below request a real page, and every route a
    // browser reaches lives under a /{locale}/ prefix built from the
    // `languages` table — with no schema there is no /ar/ and the request
    // 404s out of the router before any middleware runs.
    use RefreshDatabase;

    private LanReachability $reachability;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reachability = app(LanReachability::class);
        $this->file = storage_path('framework/last-lan-client.json');

        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_nothing_is_reported_before_any_device_has_connected(): void
    {
        $this->assertNull($this->reachability->lastSeen());
    }

    public function test_it_records_a_device_from_the_network(): void
    {
        $this->reachability->record('192.168.1.42');

        $seen = $this->reachability->lastSeen();

        $this->assertSame('192.168.1.42', $seen['ip']);
        $this->assertLessThan(5, $seen['seconds_ago']);
    }

    public function test_the_machine_talking_to_itself_proves_nothing(): void
    {
        // The control window, the panel's own status poll and the owner
        // opening the menu to check it looks right all arrive this way, and
        // none of them says anything about whether a tablet could.
        $this->assertTrue($this->reachability->isLoopback('127.0.0.1'));
        $this->assertTrue($this->reachability->isLoopback('::1'));
        $this->assertTrue($this->reachability->isLoopback(null));
        $this->assertFalse($this->reachability->isLoopback('192.168.1.42'));
    }

    public function test_repeat_visits_do_not_rewrite_the_file(): void
    {
        // The throttle is the whole cost argument: this runs in middleware on
        // a server that answers one request at a time, so the common path has
        // to be a single filemtime() and nothing else.
        $this->reachability->record('192.168.1.42');
        $first = filemtime($this->file);

        touch($this->file, time() - 5);
        clearstatcache(true, $this->file);

        $this->reachability->record('192.168.1.99');

        $this->assertSame('192.168.1.42', $this->reachability->lastSeen()['ip']);
        $this->assertNotNull($first);
    }

    public function test_it_records_again_once_the_throttle_has_passed(): void
    {
        $this->reachability->record('192.168.1.42');

        touch($this->file, time() - 120);
        clearstatcache(true, $this->file);

        $this->reachability->record('192.168.1.99');

        $this->assertSame('192.168.1.99', $this->reachability->lastSeen()['ip']);
    }

    public function test_a_corrupt_file_reads_as_no_device_rather_than_throwing(): void
    {
        // A half-written file after a power cut, which is an ordinary event
        // on a machine in a café with no UPS. A diagnostic must not become
        // the thing that breaks the panel it is diagnosing.
        file_put_contents($this->file, '{not json');

        $this->assertNull($this->reachability->lastSeen());
    }

    public function test_a_request_from_the_network_is_recorded_through_the_middleware(): void
    {
        // route(), never a literal '/ar/menu'. The locale prefix is produced
        // once per process, when the routes are registered — so the segment a
        // test can actually reach is whatever that produced, and a hardcoded
        // one 404s out of the router before a single middleware runs.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
            ->get(route('menu.index'));

        // Asserted so that a route the middleware never reached fails here,
        // naming the reason, rather than two lines down as "no device was
        // recorded" — which reads as a bug in the recording.
        $response->assertOk();

        $this->assertSame('192.168.1.77', $this->reachability->lastSeen()['ip']);
    }

    public function test_a_request_from_this_machine_is_not(): void
    {
        $this->get(route('menu.index'))->assertOk();

        $this->assertNull($this->reachability->lastSeen());
    }
}
