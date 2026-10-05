<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course the institute has stopped running, kept out of the way.
 *
 * Inactive means "not on sale right now" and is expected to come back.
 * Archived means "finished": hidden from the active and inactive lists so the
 * catalogue only shows what is in play, but kept with its enrolments and
 * money, and can be restored. An archived course is also inactive, so every
 * screen that offers courses (`where('is_active', true)`) already leaves it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
