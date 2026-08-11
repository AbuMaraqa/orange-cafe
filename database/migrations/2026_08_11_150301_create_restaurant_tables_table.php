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
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // Position on the admin floor-plan canvas, as a percentage
            // (0-100) of the canvas width/height — resolution-independent,
            // so the layout doesn't break across different screen sizes.
            $table->decimal('pos_x', 5, 2)->default(0);
            $table->decimal('pos_y', 5, 2)->default(0);
            $table->enum('shape', ['square', 'round'])->default('square');
            $table->unsignedInteger('seats')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
