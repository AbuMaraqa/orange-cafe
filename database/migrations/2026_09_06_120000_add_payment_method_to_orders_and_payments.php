<?php

use App\Models\OrderPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the money arrived, not just how much.
 *
 * Until now every takings figure in this application rested on an unstated
 * assumption: that money handed over is money in the drawer. That was true
 * while cash was the only way to pay, and it stops being true the moment a
 * card reader is on the counter. A card payment settles the bill without a
 * note moving, so a drawer counted at the end of the night is short by every
 * card payment of the day — and the cash box, whose one rule is that *the
 * drawer moves only when physical cash moves*, would report that honest
 * count as a discrepancy.
 *
 * So the method is recorded, and Cashbox reads it: cash payments enter the
 * balance, card payments are reported beside it and never subtracted from a
 * count. See Cashbox::collectedCents() and cardCollectedCents().
 *
 * ## Why the method lands in two places
 *
 * On `order_payments` because that is where it is *true*: a table settling
 * half in cash and half on a card makes two handovers with two methods, and
 * a single column on the bill cannot describe that. The cash box sums these
 * rows, so this is the column that has to be right.
 *
 * On `orders` because that is where it is *read*: the receipt prints from
 * the order, and the floor plan presents dozens of tables at once. Deriving
 * the method from the payment rows would mean loading every bill's timeline
 * to render a badge. It carries the most recent handover's method, exactly
 * as `paid_by` carries the most recent handover's cashier — a summary of the
 * timeline, never the source of truth for it.
 *
 * The card reference lives beside each, for the same split: one per card
 * transaction on the payment row, the latest on the bill for the receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            // Defaulted rather than nullable, and the default is the honest
            // answer for every row that exists: this café has taken cash and
            // nothing else. A null here would mean "unknown", which is not a
            // state any payment has ever been in, and would force every
            // reader — the cash box above all — to decide what an unknown
            // method does to the drawer.
            $table->string('method', 20)->default(OrderPayment::METHOD_CASH)->after('amount_cents');

            // The card terminal's own number for the transaction, so a
            // disputed charge on a statement can be traced back to a bill.
            // Nullable because a cash handover has nothing to reference.
            $table->string('reference', 60)->nullable()->after('method');

            // The cash box asks one question of this table — "what cash
            // arrived on this day" — and it now filters on method as well as
            // date. Extends the existing received_at index rather than
            // adding a second one beside it.
            $table->index(['method', 'received_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Nullable here, unlike on the payment row: an unpaid bill has
            // no payment method, and defaulting it to cash would have every
            // open table on the floor plan claiming to have been paid in
            // cash. Null means "nothing has been handed over yet", the same
            // distinction `amount_paid_cents` makes.
            $table->string('payment_method', 20)->nullable()->after('amount_paid_cents');
            $table->string('payment_reference', 60)->nullable()->after('payment_method');
        });

        $this->backfill();
    }

    /**
     * Every payment already taken was taken in cash.
     *
     * Not a guess: card payments could not be recorded before this migration,
     * so cash is what every historic row is. Stating it explicitly matters
     * because the alternative — leaving history unmarked and having readers
     * treat unmarked as cash — puts the same assumption in every query that
     * touches the table, where it can be forgotten in exactly one of them.
     *
     * `order_payments.method` already defaults to cash for existing rows, so
     * only the bills need saying.
     */
    private function backfill(): void
    {
        DB::table('orders')
            ->whereNull('payment_method')
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhere('amount_paid_cents', '>', 0);
            })
            ->update(['payment_method' => OrderPayment::METHOD_CASH]);
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropIndex(['method', 'received_at']);
            $table->dropColumn(['method', 'reference']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
