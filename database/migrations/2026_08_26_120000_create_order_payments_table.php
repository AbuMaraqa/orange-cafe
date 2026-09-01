<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When money actually crossed the counter, per handover.
 *
 * The cash box has one rule — the drawer moves when physical cash moves — and
 * until now it had no way to honour it for a bill paid in instalments. An
 * order carries `amount_paid_cents` (a running total) and `paid_at` (the
 * moment it was *settled*), and the cash box selects on `paid_at`. So a table
 * paying half at eleven and the rest tomorrow put fifty in the drawer that no
 * figure describing the drawer could see, and then put the whole hundred on
 * tomorrow — including the fifty that arrived last night.
 *
 * That is the same failure the cash box already documents having fixed once,
 * for `created_at` versus `paid_at`: the day the money arrived counts short
 * and the day the bill settles counts over, so a cashier counting an honest
 * till is told twice that it is wrong.
 *
 * A running total cannot answer "when", however it is queried. So the moments
 * are recorded, one row per handover, and the cash box reads those.
 *
 * `orders.amount_paid_cents` stays exactly where it is and keeps its meaning:
 * what this bill has taken in total, which is what the receipt prints and
 * every screen shows. This table is the timeline underneath it, and the two
 * are kept in step the same way an expense and its cash movement are — see
 * ExpenseRecorder for the pattern and OrderPaymentsMatchTheBillTest for the
 * invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Signed, and negative is meaningful: correcting a bill downwards
            // after money was taken is cash going back across the counter,
            // and a timeline that could only go up would leave the drawer
            // holding money it had already handed back.
            $table->integer('amount_cents');

            // The whole reason this table exists. Indexed with order_id
            // because the two questions asked of it are "what did this bill
            // take" and "what arrived on this day".
            $table->timestamp('received_at');

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('received_at');
            $table->index(['order_id', 'received_at']);
        });

        $this->backfill();
    }

    /**
     * Every payment that already happened, as one row each.
     *
     * Without this the cash box reports zero takings for the whole of the
     * café's history the moment it starts reading this table — every open day
     * empty, every figure wrong. Closed days are safe either way (a
     * DailyClosing stores its figures rather than recomputing them), which is
     * exactly why the open ones have to be carried across by hand.
     *
     * One row per order, not one per handover: the handovers were never
     * recorded, so the best that exists is the total and the moment it
     * settled. That reproduces today's figures precisely, which is the point
     * — this migration must change no number that is currently right.
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('orders')
            ->select('id', 'total_cents', 'amount_paid_cents', 'paid_at', 'paid_by', 'updated_at')
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhere('amount_paid_cents', '>', 0);
            })
            ->orderBy('id')
            ->chunk(500, function ($orders) use ($now) {
                $rows = [];

                foreach ($orders as $order) {
                    // Order::collectedCents() — the null fallback covers rows
                    // predating the column, which were fully paid.
                    $amount = $order->amount_paid_cents ?? $order->total_cents;

                    if ((int) $amount === 0) {
                        continue;
                    }

                    $rows[] = [
                        'order_id' => $order->id,
                        'amount_cents' => (int) $amount,
                        // A settled bill knows when it settled. One still
                        // open and part-paid does not — nothing ever wrote
                        // that moment down — so its last touch is the closest
                        // thing that exists. It is a guess only for bills
                        // that are open right now, and a better one than the
                        // status quo, where that money is in the drawer and
                        // in no figure at all.
                        'received_at' => $order->paid_at ?? $order->updated_at ?? $now,
                        'received_by' => $order->paid_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('order_payments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
