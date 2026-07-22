<?php

namespace App\Support;

/**
 * Navigation for the super admin panel.
 *
 * Unlike {@see Nav}, nothing here is permission-gated. The super admin is not
 * permission-scoped by design, see the note on the `super_admins` migration.
 * Restraint comes from mandatory TOTP, per-action step-up and the audit trail,
 * not from hiding menu items.
 */
final class SuperNav
{
    /** @return array<string, list<array{route:string,label:string,icon:string}>> */
    public static function sections(): array
    {
        return [
            'Overview' => [
                ['route' => 'superadmin.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                ['route' => 'superadmin.performance', 'label' => 'Performance', 'icon' => 'trending-up'],
            ],
            'Records' => [
                ['route' => 'superadmin.staff', 'label' => 'Staff', 'icon' => 'staff'],
                ['route' => 'superadmin.students', 'label' => 'Students', 'icon' => 'students'],
            ],
            'Oversight' => [
                ['route' => 'superadmin.activity', 'label' => 'Activity log', 'icon' => 'activity'],
                ['route' => 'superadmin.backups', 'label' => 'Backup & export', 'icon' => 'database'],
            ],
        ];
    }

    /** @return array{0:string,1:string} title, subtitle */
    public static function pageMeta(?string $route): array
    {
        return match ($route) {
            'superadmin.dashboard' => ['Platform overview', 'Institute-wide figures and system health'],
            'superadmin.performance' => ['Performance', 'Who is enrolling, and who is collecting'],
            'superadmin.staff' => ['Staff', 'Accounts, passwords, two-factor and removal'],
            'superadmin.students' => ['Students', 'Every record, including removed ones'],
            'superadmin.activity' => ['Activity log', 'Every consequential action, append-only'],
            'superadmin.backups' => ['Backup & export', 'Download the database and per-table CSVs'],
            'superadmin.two-factor.setup' => ['Two-factor', 'Required before the panel opens'],
            default => ['Super admin', 'Platform administration'],
        };
    }
}
