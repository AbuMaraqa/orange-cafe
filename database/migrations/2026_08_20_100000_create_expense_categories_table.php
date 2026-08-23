<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an expense is filed under: رواتب, إيجار, صيانة, and so on.
 *
 * A plain `name` column rather than the translation table every other
 * catalog entity here uses. Those are read by customers, in whichever
 * language they picked; these are read only by whoever runs the cafe, on
 * an Arabic-only back-office screen. Making them translatable would mean a
 * two-tab form for the word "صيانة" — the opposite of "أكتب فقط: صيانة ثم
 * حفظ".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Two categories called "صيانة" would split the same spending in
            // two on every report, and nothing on the screen would explain
            // why the totals look halved.
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
