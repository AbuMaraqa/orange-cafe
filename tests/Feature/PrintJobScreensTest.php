<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrintQueue;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The screens: what the admin sees, what the cashier sees, and the one
 * button that moves a held ticket onto paper.
 */
class PrintJobScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function printer(string $station = 'kitchen'): Printer
    {
        return Printer::create([
            'name' => 'طابعة '.$station,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.fake()->unique()->numberBetween(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'confirmed',
            'total_cents' => 1000,
        ]);

        $order->items()->create([
            'product_name' => 'ستيك',
            'station' => 'kitchen',
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'subtotal_cents' => 1000,
        ]);

        return $order->fresh('items');
    }

    private function failedJob(): PrintJob
    {
        $queue = app(PrintQueue::class);
        $job = $queue->reserve($this->order(), $this->printer(), 'kitchen', PrintJob::KIND_KITCHEN, 1);

        $job->forceFill([
            'status' => PrintJob::PRINTER_OFFLINE,
            'attempts' => PrintJob::MAX_ATTEMPTS,
            'last_error' => 'unreachable',
        ])->save();

        return $job->fresh();
    }

    public function test_the_unprinted_list_renders(): void
    {
        $this->failedJob();

        $this->actingAs($this->user('admin'))
            ->get(route('admin.print-jobs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Printers/Jobs')
                ->has('jobs', 1)
                ->where('counts.needs_attention', 1));
    }

    public function test_the_printers_screen_shows_state_and_routing(): void
    {
        $printer = $this->printer('kitchen');
        // Twice: one refused check no longer marks a printer down, because
        // these printers refuse a check while they are mid-ticket — see
        // PrinterBlipDoesNotHoldTicketsTest.
        app(PrinterHealth::class)->markUnreachable($printer, 'Connection refused');
        app(PrinterHealth::class)->markUnreachable($printer->fresh(), 'Connection refused');

        $this->actingAs($this->user('admin'))
            ->get(route('admin.printers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Printers/Index')
                ->where('printers.0.status', PrinterHealth::OFFLINE)
                ->where('printers.0.last_error', 'Connection refused'));
    }

    /**
     * A reprint is a new row, dispatched on purpose. The original stays as
     * the record that the printer was down.
     */
    public function test_reprinting_queues_a_fresh_copy(): void
    {
        Queue::fake();

        $job = $this->failedJob();

        $this->actingAs($this->user('admin'))
            ->post(route('admin.print-jobs.reprint', $job))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PrintJob::query()->count());
        $this->assertSame(PrintJob::PRINTER_OFFLINE, $job->fresh()->status, 'the failure stays on the record');

        $copy = PrintJob::query()->latest('id')->first();
        $this->assertSame(PrintJob::PENDING, $copy->status);
        $this->assertSame(0, $copy->attempts);
    }

    public function test_an_already_printed_job_cannot_be_reprinted_from_the_list(): void
    {
        Queue::fake();

        $job = $this->failedJob();
        $job->forceFill(['status' => PrintJob::PRINTED, 'printed_at' => now()])->save();

        $this->actingAs($this->user('admin'))
            ->post(route('admin.print-jobs.reprint', $job))
            ->assertSessionHasErrors('job');

        $this->assertSame(1, PrintJob::query()->count(), 'no second copy may be created by accident');
    }

    public function test_a_job_with_no_printer_cannot_be_reprinted(): void
    {
        Queue::fake();

        $order = $this->order();
        app(PrintQueue::class)->reserveUnroutable($order, 'grill', PrintJob::KIND_KITCHEN, 1);

        $orphan = PrintJob::query()->firstOrFail();

        $this->actingAs($this->user('admin'))
            ->post(route('admin.print-jobs.reprint', $orphan))
            ->assertSessionHasErrors('job');
    }

    // ---------------------------------------------------------------
    // The cashier's side
    // ---------------------------------------------------------------

    public function test_the_alert_endpoint_reports_only_what_needs_a_person(): void
    {
        $this->failedJob();

        // A pending job is still being tried; it is not something to put in
        // front of a cashier yet.
        app(PrintQueue::class)->reserve($this->order(), $this->printer('drinks'), 'drinks', PrintJob::KIND_KITCHEN, 2);

        $this->actingAs($this->user('cashier'))
            ->getJson(route('staff.print-alerts'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.items_count', 1);
    }

    /**
     * The alert carries names and counts, never the technical detail — a
     * cashier standing in front of a customer must not be shown a socket
     * error.
     */
    public function test_the_alert_hides_the_technical_detail(): void
    {
        $this->failedJob();

        $response = $this->actingAs($this->user('cashier'))
            ->getJson(route('staff.print-alerts'))
            ->assertOk();

        $this->assertStringNotContainsString('unreachable', $response->getContent());
        $this->assertStringNotContainsString('attempts', $response->getContent());
    }

    public function test_a_waiter_can_read_the_alert_but_not_the_admin_list(): void
    {
        $waiter = $this->user('waiter');

        $this->actingAs($waiter)->getJson(route('staff.print-alerts'))->assertOk();
        $this->actingAs($waiter)->get(route('admin.print-jobs.index'))->assertForbidden();
    }
}
