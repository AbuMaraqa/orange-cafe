<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a printer remembers between checks.
 *
 * `recovered_at` is the important one, and it exists to enforce a rule that
 * is easy to get backwards: when a dead printer comes back, it must NOT
 * spit out the hour of tickets it missed. A grill printer that returns at
 * 13:00 and immediately prints orders #100, #101 and #102 hands the kitchen
 * three meals that were served, cancelled or comped an hour ago — and the
 * kitchen has no way to tell those from the live ones.
 *
 * So recovery stamps the moment, and only orders created *after* it are
 * allowed to print automatically. Everything older stays on the reprint
 * screen until a person decides it is still wanted. `offline_since` is the
 * other half of the pair, kept so the screen can say how long it has been
 * down rather than only that it is.
 *
 * `last_error` is stored because "offline" on its own tells whoever has to
 * fix it nothing — a refused socket, a missing Windows share and a wrong IP
 * all look identical without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->timestamp('recovered_at')->nullable()->after('status_checked_at');
            $table->timestamp('offline_since')->nullable()->after('recovered_at');
            $table->text('last_error')->nullable()->after('offline_since');

            // Consecutive failed sends. Reset on any success, so it measures
            // "is it down now", not "has it ever been down".
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['recovered_at', 'offline_since', 'last_error', 'consecutive_failures']);
        });
    }
};
