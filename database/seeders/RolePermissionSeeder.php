<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Seeds the two shipped system roles (spec §4.1):
 *  - Administrator (admin, navy), every permission, and TOTP required.
 *  - Admission Officer (officer, orange), create & collect, scoped to own
 *    enrolments and blind to institute-wide money. No TOTP by default.
 * Roles are editable data; access derives from permissions, never role names.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::updateOrCreate(
            ['id' => 'admin'],
            [
                'name' => 'Administrator', 'is_system' => true, 'tone' => 'navy',
                // Admins can move money, rewrite roles and change bank details.
                // Every one of those is worth a second factor.
                'requires_2fa' => true,
            ],
        );
        $officer = Role::updateOrCreate(
            ['id' => 'officer'],
            [
                'name' => 'Admission Officer', 'is_system' => true, 'tone' => 'orange',
                // Front-desk staff on a shared counter are not forced into TOTP
                // out of the box; flip this in the role editor when every
                // officer has the app installed.
                'requires_2fa' => false,
            ],
        );

        $this->grant($admin->id, Permissions::keys());
        $this->grant($officer->id, [
            'dashboard.view',
            'registrations.view',
            'registrations.create',
            'challans.view',
            'challans.pay',
            'students.view',
            // Taking the register is a daily front-desk job, so officers hold
            // it out of the box even though they cannot edit the catalog the
            // register is taken against.
            'attendance.manage',
            // Note: NOT students.manage, officers enrol students but do not
            // edit an existing student's identity (see Permissions::CATALOG).
        ]);
    }

    /** @param list<string> $keys */
    private function grant(string $roleId, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::firstOrCreate(['role_id' => $roleId, 'permission_key' => $key]);
        }
    }
}
