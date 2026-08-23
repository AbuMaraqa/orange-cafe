<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of an employee's account: a سلفة taken, a غياب deducted, a
 * راتب paid.
 *
 * Signed rather than debit/credit columns. The screen shows مدين/دائن, but
 * two columns of which exactly one is always zero is a shape that invites
 * rows where both are filled, and every sum then has to remember to
 * subtract one from the other. A single signed integer cannot represent an
 * inconsistent row at all: positive is owed *by* the employee to the cafe
 * (an advance they took, a deduction against them), negative is owed *to*
 * them (salary earned, a repayment they made). The running balance is the
 * plain sum, and the presenter splits the sign back into two columns.
 *
 * `source_type`/`source_id` point back at whatever caused the line — the
 * absence, the expense. That reference is what makes editing work: an
 * absence whose deduction changes from 50 to 30 updates its own entry
 * instead of adding a second one, and deleting the absence takes its entry
 * with it. Without it the only way to keep the two in step would be to
 * match on amount and date and hope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('amount_cents');
            $table->date('occurred_on');
            $table->string('description');
            $table->text('notes')->nullable();

            // Nullable rather than required: entries are also written by the
            // seeder and by console commands, where there is no acting user,
            // and a row that cannot be saved is worse than one that cannot
            // name who saved it.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->nullableMorphs('source');
            $table->timestamps();

            // The statement is always "this employee, in date order".
            $table->index(['user_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_ledger_entries');
    }
};
