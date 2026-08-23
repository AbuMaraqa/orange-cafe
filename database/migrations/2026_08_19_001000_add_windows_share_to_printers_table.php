<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Windows share name a spooler-connected printer is published under.
 *
 * Added so a USB till printer can work in the packaged app at all: direct
 * USB needs PHP's FFI extension, which the bundled runtime does not have and
 * cannot be given. Going through the Windows spooler sidesteps the whole
 * problem — Windows already has the driver.
 *
 * ip_address doubles as the host for a printer shared from another PC on the
 * network; left blank it means this machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('windows_share')->nullable()->after('usb_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('windows_share');
        });
    }
};
