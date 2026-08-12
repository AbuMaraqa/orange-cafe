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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // draft -> published -> archived (App\Enums\MenuPublishStatus).
            // Separate from `is_active`: a menu can be published but
            // manually switched off, or still a draft while being built.
            $table->string('publish_status')->default('draft');
            $table->boolean('is_active')->default(true);

            // Schedule-based availability — all nullable/optional, so a
            // menu with none of these set is simply "always on" once
            // published+active. See MenuResolverService for how these
            // combine into published / active / visible.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->json('days_of_week')->nullable()->comment('0=Sunday..6=Saturday, null=every day');

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->string('locale')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['menu_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_translations');
        Schema::dropIfExists('menus');
    }
};
