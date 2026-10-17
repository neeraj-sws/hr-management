<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen capture transparency: which version of the capture terms (interval,
 * window, purpose, retention…) a person was told about by e-mail/in-app, and
 * which version they acknowledged. A change to the terms asks again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('screen_capture_notified_terms', 64)->nullable();
            $table->string('screen_capture_ack_terms', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['screen_capture_notified_terms', 'screen_capture_ack_terms']);
        });
    }
};
