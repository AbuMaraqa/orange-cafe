<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The print queue: one row per (order, printer, station) piece of paper
 * that should exist.
 *
 * Until now the only record that a ticket was meant to print was a row in
 * Laravel's `jobs` table, which disappears the moment the job finishes —
 * successfully or not. So "which orders never printed?" was a question the
 * system could not answer at all, and the answer is the whole point: an
 * order is saved whether or not any printer works, and somebody has to be
 * able to see what is still on paper-less.
 *
 * `idempotency_key` is what stops a retry from becoming a second ticket.
 * It is derived from the order, the printer and the station rather than
 * generated, so the same piece of paper always resolves to the same row no
 * matter how many times it is asked for — a repeat "print" click, a
 * lifecycle hook firing twice, a worker that died after printing but
 * before recording it. A kitchen that receives the same order twice cooks
 * it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();

            // A short human reference for the cashier's alert and the
            // reprint screen — "#125" means nothing without it.
            $table->string('reference')->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nullable so the record survives a printer being deleted: the
            // fact that an order never printed does not stop being true
            // because someone removed the printer from the settings.
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();

            // Kept as plain strings, not foreign keys: they are what the
            // ticket said at the time, and must stay readable after the
            // printer is renamed or the station reassigned.
            $table->string('station');
            $table->string('printer_name');

            $table->string('kind');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->text('last_error')->nullable();

            // How many lines this ticket covers, so the alert can say "2
            // items did not print" without re-deriving it.
            $table->unsignedSmallInteger('items_count')->default(0);

            // Set when a ticket was printed somewhere other than its own
            // printer, so the audit trail says where the paper actually is.
            $table->foreignId('printed_on_printer_id')->nullable()->constrained('printers')->nullOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The dedup key. Unique at the database level rather than
            // checked in PHP: two workers picking up the same order at the
            // same moment would both find nothing and both insert.
            $table->string('idempotency_key')->unique();

            // "What still has not printed" and "what happened on this
            // printer" are the two questions asked of this table.
            $table->index(['status', 'created_at']);
            $table->index(['printer_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
