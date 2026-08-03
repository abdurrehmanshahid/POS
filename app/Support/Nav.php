<?php

namespace App\Support;

use App\Models\User;

/**
 * Navigation, page titles and the "first permitted screen" landing logic
 * (spec §8.1 / §8.2 / §13). Every item is permission-gated; sections render
 * only when at least one child is permitted.
 */
final class Nav
{
    /**
     * Sidebar sections → items. Each item: [route, permission, label, icon].
     *
     * @return array<string, list<array{route:string,perm:string,label:string,icon:string}>>
     */
    public static function sections(): array
    {
        return [
            'Overview' => [
                ['route' => 'dashboard', 'perm' => 'dashboard.view', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ],
            'Operations' => [
                ['route' => 'registrations', 'perm' => 'registrations.view', 'label' => 'Registrations', 'icon' => 'registrations'],
                ['route' => 'challans', 'perm' => 'challans.view', 'label' => 'Fee Challans', 'icon' => 'challans'],
            ],
            'Catalog & People' => [
                ['route' => 'courses', 'perm' => 'courses.view', 'label' => 'Courses', 'icon' => 'courses'],
                ['route' => 'cohorts', 'perm' => 'cohorts.manage', 'label' => 'Batches', 'icon' => 'users'],
                ['route' => 'attendance', 'perm' => 'attendance.manage', 'label' => 'Attendance', 'icon' => 'check-circle'],
                ['route' => 'students', 'perm' => 'students.view', 'label' => 'Students', 'icon' => 'students'],
                ['route' => 'staff', 'perm' => 'staff.view', 'label' => 'Staff & Roles', 'icon' => 'staff'],
            ],
            'Insight' => [
                ['route' => 'reports', 'perm' => 'reports.view', 'label' => 'Reports', 'icon' => 'reports'],
                ['route' => 'datamodel', 'perm' => 'datamodel.view', 'label' => 'Data Model', 'icon' => 'datamodel'],
                ['route' => 'settings', 'perm' => 'settings.manage', 'label' => 'Settings', 'icon' => 'settings'],
            ],
        ];
    }

    /** First screen the user may see after login (spec §8.1). */
    public static function firstScreen(User $user): string
    {
        $order = [
            'dashboard' => 'dashboard.view',
            'registrations' => 'registrations.view',
            'challans' => 'challans.view',
            'students' => 'students.view',
            'courses' => 'courses.view',
            'cohorts' => 'cohorts.manage',
            'attendance' => 'attendance.manage',
            'staff' => 'staff.view',
            'reports' => 'reports.view',
            'datamodel' => 'datamodel.view',
            'settings' => 'settings.manage',
        ];
        foreach ($order as $route => $perm) {
            if ($user->can($perm)) {
                return $route;
            }
        }

        return 'dashboard';
    }

    /**
     * Header title + subtitle for a screen (spec §13).
     *
     * @return array{0:string,1:string}
     */
    public static function pageMeta(string $route, ?User $user = null): array
    {
        return match ($route) {
            'dashboard' => ['Dashboard', $user && $user->can('revenue.view')
                ? 'Institute overview, reconciled figures'
                : 'Your students, operational view'],
            'registrations' => ['Registrations', 'Enrol students and track status'],
            'challans' => ['Fee Challans', 'Vouchers, payments and audit'],
            'courses' => ['Courses', 'Catalog, fees and capacity'],
            'cohorts' => ['Batches', 'Course intakes and their students'],
            'attendance' => ['Attendance', 'Take the register and review it'],
            'students' => ['Students', 'Student records and fee status'],
            'staff' => ['Staff & Roles', 'Users, roles and permissions'],
            'reports' => ['Reports', 'Collections, dues and officer performance'],
            'datamodel' => ['Data Model', 'Entities, keys and relationships'],
            'settings' => ['Settings', 'Institute configuration, admin only'],
            default => ['Big Binary Tech', ''],
        };
    }
}
