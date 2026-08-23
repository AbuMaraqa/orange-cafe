<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things a table is charged for that nobody cooks.
     *
     * A seat held for three hours, a service charge, a corkage — money on
     * the bill with no plate behind it. Deliberately not a product: a
     * product belongs on the menu, is browsable by a customer, prints to a
     * station and can be ordered. None of that is true here, and modelling
     * it as a product with three flags switched off would put it in front
     * of customers on the day somebody forgets one of them.
     *
     * Separate from `services`, which is the customer's call bell ("bring
     * the bill", "extra chair") and carries no money at all.
     */
    public function up(): void
    {
        Schema::create('service_charges', function (Blueprint $table) {
            $table->id();
            // The suggested price. Copied onto the line when it is added,
            // and editable there like any other line — a two-hour seat is
            // not always charged the same way.
            $table->unsignedInteger('amount_cents');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_charge_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_charge_id')->constrained()->cascadeOnDelete();
            $table->string('locale')->index();
            $table->string('name');
            $table->timestamps();

            $table->unique(['service_charge_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_charge_translations');
        Schema::dropIfExists('service_charges');
    }
};
