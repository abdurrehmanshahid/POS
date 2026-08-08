<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guarantee the single institute settings row exists on every install.
 *
 * This closes a hole that made a clean production install unable to bill
 * anybody. `settings` is a singleton config row, and `Setting::current()` and
 * `Sequences::nextChallanNo()` both reach it with `firstOrFail()`. The only
 * thing that ever created it was DemoDataSeeder, which a production install is
 * specifically told not to run (see docs/DEPLOYMENT.md §5). Following the safe
 * documented path therefore produced a system where:
 *
 *   - issuing any fee challan threw ModelNotFoundException,
 *   - the challan PDF threw,
 *   - and the Settings screen threw,
 *
 * while enrolment without billing quietly succeeded, so the failure only
 * surfaced at the counter on the first real registration.
 *
 * It lives in a migration rather than a seeder because seeders are optional on
 * deploy and migrations are not. The row is infrastructure, not sample data.
 *
 * Idempotent: it inserts only into an empty table, so re-running it on an
 * install that already has settings, demo or real, changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            // Deliberately generic. The institute sets its own name, bank and
            // IBAN on the Settings screen; inventing a plausible-looking bank
            // account here would risk it being printed on a real challan.
            'name' => 'Institute',
            'bank' => '',
            'account' => '',
            'iban' => '',
            // Starts at 1. The demo data starts at 1086 to reproduce the
            // prototype's numbering; a real install has issued nothing yet.
            'next_challan_serial' => 1,
            'twofa_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Nothing. Deleting the row would break the application, and this
        // migration cannot tell the row it inserted from one the institute has
        // since edited.
    }
};
