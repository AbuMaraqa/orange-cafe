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
        Schema::table('printers', function (Blueprint $table) {
            // Most printers are network (ip_address/port); a USB-connected
            // one (e.g. the cashier's receipt printer) has neither — it's
            // addressed by USB vendor/product ID instead, over a raw bulk
            // transfer (see App\Services\Printing\PrinterTransport).
            $table->string('connection_type')->default('network')->after('name');
            $table->string('usb_vendor_id')->nullable()->after('port');
            $table->string('usb_product_id')->nullable()->after('usb_vendor_id');
            $table->string('ip_address')->nullable()->change();
            $table->unsignedInteger('port')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['connection_type', 'usb_vendor_id', 'usb_product_id']);
            $table->string('ip_address')->nullable(false)->change();
            $table->unsignedInteger('port')->nullable(false)->default(9100)->change();
        });
    }
};
