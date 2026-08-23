<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day (or part of one) an employee did not work, and what it cost them.
 *
 * `deduction_cents` may be zero — إجازة مرضية usually is — and zero is a
 * real answer, not a missing one: it records that the absence happened and
 * was not charged for. Only a non-zero deduction produces a line on the
 * employee's account.
 *
 * Note what is deliberately absent: any link to the cash box. A deduction
 * takes nothing out of the drawer; it reduces what the cafe owes the
 * employee at the end of the month. Charging it against the till would
 * make the till count short by that amount every single day, and the
 * nightly close would report a phantom surplus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('absence_category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('deduction_cents')->default(0);
            $table->date('absent_on');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'absent_on']);
            $table->index('absent_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
