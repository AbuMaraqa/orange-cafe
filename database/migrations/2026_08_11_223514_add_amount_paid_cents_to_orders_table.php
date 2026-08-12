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
            // The cash actually collected — a cashier may round down or
            // discount at the till (bill is 202, takes 200), so this can
            // legitimately differ from total_cents. Null until paid, then
            // defaults to total_cents if the cashier didn't type an amount.
            $table->unsignedInteger('amount_paid_cents')->nullable()->after('paid_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('amount_paid_cents');
        });
    }
};
