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
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The kitchen section this printer serves (e.g. "grill", "bar").
            // Matched against categories.station / products.station — free
            // text, so adding a new station is just naming it on a printer.
            $table->string('station');
            $table->string('ip_address');
            $table->unsignedInteger('port')->default(9100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
