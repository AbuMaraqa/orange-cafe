<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The night's count, and the record that a day is finished with.
 *
 * Every figure is stored, not recomputed on demand, and that is the point.
 * A closing is a statement about what was true at the moment it was signed:
 * if an order is refunded next week, or an expense is backdated, today's
 * recomputed "collected" changes and the difference the manager actually
 * counted and signed off would silently rewrite itself. Storing the numbers
 * makes the closing evidence rather than a view.
 *
 *   expected = opening + collected − cash expenses − cash discounts
 *   difference = counted − expected
 *
 * A positive difference is زيادة, negative is عجز, zero is مطابق. It is
 * never hidden or rounded away: an unexplained 2 is how a much larger
 * problem announces itself early.
 *
 * The opening balance is not entered by anyone. It is the previous
 * closing's *counted* figure — the money actually left in the drawer — so
 * the chain of days cannot drift away from reality one rounding at a time.
 *
 * Reopening is recorded rather than allowed silently: a closed day that can
 * be quietly edited is not a close at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();

            // One closing per day, enforced here rather than in a controller
            // — two managers closing at once would otherwise both succeed
            // and the next day's opening balance would depend on which row
            // happened to be read.
            $table->date('business_date')->unique();

            $table->integer('opening_cents')->default(0);
            $table->integer('collected_cents')->default(0);
            $table->integer('expenses_cents')->default(0);
            $table->integer('cash_discounts_cents')->default(0);

            // Recorded for the summary even though it does not enter the
            // arithmetic — see the absences migration for why deductions
            // never touch the drawer.
            $table->integer('staff_deductions_cents')->default(0);

            $table->integer('expected_cents')->default(0);
            $table->integer('counted_cents')->default(0);
            $table->integer('difference_cents')->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
