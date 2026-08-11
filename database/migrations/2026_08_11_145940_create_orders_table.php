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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('table_number')->nullable();
            $table->string('customer_name')->nullable();
            // pending -> confirmed -> preparing -> ready -> completed, or cancelled.
            // Kept as a plain string (not a DB enum) so new statuses/workflows
            // don't require a migration — see config('orders.statuses').
            $table->string('status')->default('pending');
            $table->string('locale', 12)->nullable()->comment('locale the order was placed in');
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
