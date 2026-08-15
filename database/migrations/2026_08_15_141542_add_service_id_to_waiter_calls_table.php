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
        Schema::table('waiter_calls', function (Blueprint $table) {
            // Null means the generic "call waiter" bell — the one request
            // type that predates this table having named services at all.
            // Nulled (not cascaded) on delete so removing a service from
            // the admin list doesn't erase past request history.
            $table->foreignId('service_id')->nullable()->after('table_number')->constrained('services')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waiter_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
