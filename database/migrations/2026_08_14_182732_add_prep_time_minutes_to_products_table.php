<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable: not every product needs an estimate shown (e.g. a
            // bottled drink), and kitchen/menu display should just omit it
            // rather than showing a fake "0 min".
            $table->unsignedSmallInteger('prep_time_minutes')->nullable()->after('price_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('prep_time_minutes');
        });
    }
};
