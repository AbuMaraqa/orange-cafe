<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash entering or leaving the drawer for a reason that is not a customer
 * order — an owner top-up, a refund handed back, money taken to the bank.
 *
 * Deliberately NOT where order takings live. Those are already recorded on
 * `orders` (paid_at, amount_paid_cents) and the day's collection is summed
 * from there. Copying them here would create two records of the same money,
 * and the first bug would be a cashier voiding an order and leaving the
 * copy behind — the drawer would then never reconcile and nothing on screen
 * would say which of the two numbers was the real one.
 *
 * `direction` is 'in' or 'out'; the amount stays unsigned so a row cannot
 * contradict itself by being an 'in' of minus 50.
 *
 * `source` points back at whatever produced the movement — the expense a
 * cash payment came from — so editing that record updates this one instead
 * of leaving a stale duplicate behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('direction');
            $table->string('type');
            $table->unsignedInteger('amount_cents');
            $table->date('occurred_on');
            $table->string('description');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['occurred_on', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
