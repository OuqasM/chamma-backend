<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store settings the owner edits from the admin panel.
 *
 * A key/value table rather than a column per setting. The store has a handful of
 * values that change without a deploy (where to send order alerts, whether to
 * send them at all) and a long tail of ones that probably never will. A column
 * per setting would mean a migration for every new toggle.
 *
 * `value` is a string because the only settings so far are text; anything
 * needing a type — a boolean, a number — is stored as text and cast by the
 * reading service rather than guessed at from the string's shape. Guessing
 * `false` from the absence of a key and `0` from the string "0" differently is
 * exactly the sort of thing that reads as a bug three months later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
