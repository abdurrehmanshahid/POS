<?php

/**
 * Mapping tables for `php artisan roll:import`.
 *
 * The importer never guesses. Every free-text value in the spreadsheet must
 * resolve through an EXACT match — against a course code, a course title, or an
 * alias listed here — and a value that resolves to zero courses, or to more than
 * one, is rejected rather than assigned to whichever looked closest.
 *
 * That rule is not fussiness. This import creates admissions, invoices and
 * payment history; a fuzzy match that puts a student on the wrong course bills
 * the wrong fee, and the mistake is discovered when somebody chases the wrong
 * person for money. A rejection costs somebody one line in a spreadsheet.
 *
 * The intended loop is: run the dry-run, read the "unresolved values" section it
 * prints, add the entries here (or create the missing course), run it again.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Course aliases
    |--------------------------------------------------------------------------
    |
    | spreadsheet text => course code in the `courses` table.
    |
    | Matching order is: exact code, exact title, then this map. All three are
    | compared case-insensitively with collapsed whitespace, because the roll
    | contains "Graphic Designing (Level-1  Part-B)" with a double space and
    | "Odoo ERP DEVELOPMENT" in caps, and neither is a different course.
    |
    | A spreadsheet cell may name SEVERAL courses separated by commas. Each part
    | is resolved independently and the row becomes one invoice billing several
    | enrolments, which is what `challans.admission_id` was widened to support.
    |
    */
    'course_aliases' => [
        'video editing + youtube automation' => 'VE-101',
        'odoo erp development' => 'ODOO-301',
        'web development' => 'WD-101',
        'stem robotics' => 'ROB-101',
        'dmm (level-1 part-a)' => 'DMM-101',
    ],

    /*
    |--------------------------------------------------------------------------
    | Values that are not courses at all
    |--------------------------------------------------------------------------
    |
    | The Course column also carries line items that are not enrolments: a room
    | booking, a certificate fee, a recovery batch. These are rejected with a
    | reason that says so, rather than appearing in the report as an unknown
    | course somebody then tries to create.
    |
    */
    'not_courses' => [
        'co-working space',
        'certificate fee (batch# 02)',
        'recovery (batch 4)',
    ],

    /*
    |--------------------------------------------------------------------------
    | CSR (the officer who enrolled the student) => users.username
    |--------------------------------------------------------------------------
    |
    | Resolved to a username, not to a display name. Two staff can share a name;
    | a username is the stable identifier, and `admissions.enrolled_by` decides
    | which officer sees the student for the rest of the record's life.
    |
    | An unmapped CSR is a rejection. Attributing an enrolment to the wrong
    | officer silently changes who can see a student and whose collection
    | figures they land in.
    |
    */
    'csr_usernames' => [
        'ali raza' => 'aliraza',
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch name canonicalisation
    |--------------------------------------------------------------------------
    |
    | The roll writes one batch six ways: "Batch 7", "Batch # 10", "Batch #09",
    | "Batch # 04". `canonical_batch()` in the importer folds all of those to
    | "Batch N"; this map is only for names that are not of that shape.
    |
    | Batches are per-course in this schema (`cohorts` is unique on
    | (course_id, name)), while the spreadsheet's batch is institute-wide. The
    | importer therefore resolves the batch WITHIN the course it already
    | resolved, and creates it there if it does not exist yet.
    |
    */
    'batch_aliases' => [],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | Nothing to configure. Recorded here because the rule is easy to get wrong
    | and the cost is high: money received comes from "Total Amount" alone,
    | never from "Advance + Second Installment". See
    | RollResolver::validateMoney(), which enforces it and explains why.
    |
    */
];
