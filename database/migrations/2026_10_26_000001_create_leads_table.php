<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads: people who have asked about a course but are not yet students.
 *
 * Every lead starts as `unread` and is walked along the pipeline by hand. The
 * stage is a plain string validated by the model, not a DB enum, so a stage can
 * be added later without an engine-specific ALTER.
 *
 * `external_id` is the id the lead had where it came from (Privyr), so a
 * webhook delivered twice cannot create the lead twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('source', 40)->default('manual');
            $table->string('external_id', 80)->nullable()->unique();
            $table->string('course_interest', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('stage', 20)->default('unread');
            $table->timestamp('stage_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage', 'created_at']);
        });

        foreach (['admin', 'officer'] as $role) {
            if (DB::table('roles')->where('id', $role)->exists()) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $role,
                    'permission_key' => 'leads.manage',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission_key', 'leads.manage')->delete();
        Schema::dropIfExists('leads');
    }
};
