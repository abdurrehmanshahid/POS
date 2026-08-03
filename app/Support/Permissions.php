<?php

namespace App\Support;

/**
 * The authoritative permission catalog (spec §3), grouped. Group order and
 * within-group order drive the role editor and the access matrix rendering.
 * DO NOT rename keys, access everywhere is derived from these, never role names.
 *
 * The spec defines 15 keys; `students.manage` is a 16th, added so that editing
 * an existing student's identity (name, CNIC, phone) is a distinct grant from
 * enrolling one, `cohorts.manage` a 17th for course batches, and
 * `attendance.manage` an 18th for taking the register. Adding keys is safe,
 * renaming them is not.
 */
final class Permissions
{
    // Group labels, in render order (spec §3).
    public const GROUP_ORDER = [
        'Overview',
        'Registrations and Fees',
        'People and Catalog',
        'Data scope',
        'Administration',
    ];

    /**
     * Ordered catalog: [key => [label, group]].
     */
    public const CATALOG = [
        'dashboard.view' => ['View dashboard',                          'Overview'],
        'revenue.view' => ['View revenue and money totals',           'Overview'],
        'reports.view' => ['View reports and analytics',              'Overview'],
        'registrations.view' => ['View registrations',                      'Registrations and Fees'],
        'registrations.create' => ['Create and enrol students',             'Registrations and Fees'],
        'challans.view' => ['View fee challans',                       'Registrations and Fees'],
        'challans.pay' => ['Record payments (mark paid)',             'Registrations and Fees'],
        'students.view' => ['View student directory',                  'People and Catalog'],
        // Editing a student record changes an identity that already appears on
        // issued challans, so it is separated from `registrations.create`
        // (which merely enrols). Granted to Administrator only by default.
        'students.manage' => ['Add and edit student records',            'People and Catalog'],
        'courses.view' => ['View course catalog',                     'People and Catalog'],
        'courses.manage' => ['Add and edit courses',                    'People and Catalog'],
        // Batches decide which intake a student's enrolment lands in, so opening
        // one moves existing records. Kept distinct from courses.manage.
        'cohorts.manage' => ['Manage course batches',                   'People and Catalog'],
        // Taking the register is a daily front-desk job, separate from editing
        // the catalog it happens against.
        'attendance.manage' => ['Take and review attendance',              'People and Catalog'],
        'staff.view' => ['View staff and roles',                    'People and Catalog'],
        'staff.manage' => ['Manage staff, roles and permissions',     'People and Catalog'],
        'scope.all' => ['See ALL students, not only own-enrolled', 'Data scope'],
        'settings.manage' => ['Manage institute settings',               'Administration'],
        'datamodel.view' => ['View data model and ERD',                 'Administration'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function label(string $key): string
    {
        return self::CATALOG[$key][0] ?? $key;
    }

    /**
     * Catalog grouped for the role editor / access matrix.
     *
     * @return array<string, list<array{key:string,label:string}>>
     */
    public static function grouped(): array
    {
        $out = array_fill_keys(self::GROUP_ORDER, []);
        foreach (self::CATALOG as $key => [$label, $group]) {
            $out[$group][] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }
}
