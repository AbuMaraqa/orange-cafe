<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the "preparing" step.
 *
 * The floor only ever needed two words for a live ticket: the kitchen has it,
 * and it is ready to carry. "Preparing" sat between them as a third tap that
 * told nobody anything the first tap had not already said, and a step staff
 * skip is worse than no step at all — a ticket left on "confirmed" all
 * evening stops meaning "just arrived".
 *
 * Any ticket sitting on the retired status is moved back to "confirmed",
 * which is what it meant: the kitchen has this and has not finished it.
 * Without this, such a ticket would fall out of `orders.active_statuses`
 * entirely — its table would read as free while food was still being cooked
 * for it, and no screen would offer any way to reach it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('status', 'preparing')->update(['status' => 'confirmed']);
    }

    /**
     * Deliberately empty.
     *
     * Rolling back restores the code that understands "preparing", but not
     * the knowledge of which tickets used to hold it — that distinction was
     * discarded above, and inventing it again would be guesswork written
     * into a bill.
     */
    public function down(): void {}
};
