<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money spent: a supplier paid, a repair, an advance handed to a waiter.
 *
 * Two flags decide what an expense touches, and they are independent:
 *
 *  - `paid_from_cash` — did this leave the drawer today? Paying the
 *    landlord by bank transfer is an expense of the business and no
 *    movement of the till, and counting it against the till would make the
 *    day's close short by the rent every month.
 *
 *  - `user_id` — is it on an employee's account? A سلفة is both: it leaves
 *    the drawer *and* it is owed back. Rent is neither.
 *
 * Keeping them apart is what stops the two halves of the system from
 * disagreeing. The alternative — one "type" column — forces a guess for
 * every combination that does not fit its list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            // The employee this is on, when it is on one. Restricted rather
            // than cascading: deleting a user must not silently erase money
            // the business paid out.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedInteger('amount_cents');
            $table->date('spent_on');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->boolean('paid_from_cash')->default(true);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every report is "this day" or "this range", usually narrowed
            // by category.
            $table->index(['spent_on', 'expense_category_id']);
            $table->index(['user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
