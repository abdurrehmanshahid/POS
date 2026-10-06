<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A student who has paused their studies.
 *
 * Frozen means "on hold, coming back": they keep their courses and their
 * fees, drop off the attendance register, and when they are unfrozen every
 * unpaid deadline that had not passed when they froze moves forward by the
 * days they were away (see App\Services\StudentFreezes). Who froze them and
 * who unfroze them is in the audit log.
 *
 * Also grants the new `students.freeze` permission to Administrator, since an
 * existing install only picks up new keys when the seeder is re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('kind');
            $table->string('freeze_reason', 255)->nullable()->after('frozen_at');
        });

        if (DB::table('roles')->where('id', 'admin')->exists()) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => 'admin',
                'permission_key' => 'students.freeze',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission_key', 'students.freeze')->delete();

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['frozen_at', 'freeze_reason']);
        });
    }
};
