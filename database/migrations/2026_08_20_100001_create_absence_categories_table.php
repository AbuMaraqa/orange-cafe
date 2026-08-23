<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of absence: غياب بدون عذر, إجازة مرضية, تأخير, and so on.
 *
 * `default_deduction_cents` is a suggestion the form pre-fills, not a rule
 * the system enforces. Whether a particular absence costs the employee
 * anything is a decision made case by case — the same "غياب بدون عذر" is
 * waived for one person and not another — so the amount stays on the
 * absence record. Without the default, though, whoever is filing five late
 * arrivals retypes the same number five times.
 *
 * `affects_pay` is what separates إجازة مرضية from غياب بدون عذر at the
 * reporting level even when both happen to be recorded at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('default_deduction_cents')->default(0);
            $table->boolean('affects_pay')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_categories');
    }
};
