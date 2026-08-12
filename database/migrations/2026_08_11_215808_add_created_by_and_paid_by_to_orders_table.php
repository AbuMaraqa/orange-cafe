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
        Schema::table('orders', function (Blueprint $table) {
            // Null for a customer's own self-service order (nobody on staff
            // "created" it); set to the acting user for a waiter-placed one.
            $table->foreignId('created_by')->nullable()->after('locale')->constrained('users')->nullOnDelete();
            // Who actually collected the cash — separate from paid_at
            // (when), for accountability on the daily collection report.
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('paid_by');
        });
    }
};
