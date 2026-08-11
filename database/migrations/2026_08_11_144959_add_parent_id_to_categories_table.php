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
        Schema::table('categories', function (Blueprint $table) {
            // Menu / submenu: only two levels deep (a subcategory's parent
            // is always a top-level category — enforced in the request
            // layer, not the schema, so the UI stays simple).
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('categories')->cascadeOnDelete();

            // Kitchen routing: which printer station handles this category's
            // tickets by default (e.g. "grill", "bar", "dessert"). Free text
            // matched against printers.station — no rigid FK, so admins can
            // define stations just by naming them on a printer. A product
            // may override this (see products.station).
            $table->string('station')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('station');
        });
    }
};
