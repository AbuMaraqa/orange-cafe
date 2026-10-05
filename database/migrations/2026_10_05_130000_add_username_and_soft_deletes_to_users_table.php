<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff log in with a username — any language, spaces allowed ("محمد مرقة")
 * — rather than an email address most of them don't have.
 *
 * And removing someone no longer removes anything else: orders, payments,
 * ledger lines and absences all point at their row, and a hard delete would
 * cascade through the ledger and blank "who did this" everywhere else. The
 * row stays, marked deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // On its own: making a column nullable rebuilds the whole table on
        // SQLite, and Laravel switches foreign keys off around the rebuild
        // so dropping the old `users` doesn't cascade into every table that
        // points at it. Kept apart from the additions below so the rebuild
        // has nothing else to reconcile.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            // nocase so "Ahmad" and "ahmad" are one account, at login and
            // in the unique index alike. Arabic has no case; this only
            // matters for Latin names.
            $table->string('username')->nullable()->collation('nocase')->after('name');
            $table->softDeletes();
        });

        // Existing accounts keep logging in with exactly what they typed
        // yesterday. An admin can rename them to something friendlier.
        DB::table('users')->whereNull('username')->update(['username' => DB::raw('email')]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
        });

        // email is left nullable: accounts created since have none, and
        // there is nothing truthful to put back in it.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->dropSoftDeletes();
        });
    }
};
