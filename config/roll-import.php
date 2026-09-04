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

        // The 27 courses added by
        // 2026_08_11_000001_add_the_courses_the_institutes_roll_actually_sells.
        // The roll's text is mapped here rather than used as the course title,
        // so the catalogue can read "Digital Media Marketing (6 Months)" while
        // still resolving the sheet's "DMM -6 Months." exactly. Every key below
        // is the raw spreadsheet text, lowercased; none is a guess.
        'basic to advance computer skills 2months' => 'BCS-101',
        'dmm (level-1 part-b)' => 'DMM-102',
        'dmm (level-2 part-a)' => 'DMM-201',
        'dmm (level-2 part-b)' => 'DMM-202',
        'dmm (level -3)' => 'DMM-301',
        'dmm -6 months.' => 'DMM-601',
        'artificial intelligence (level-1 part-b)' => 'AI-202',
        'artificial intelligence (level-2 part-a)' => 'AI-203',
        'artificial intelligence (level-2 part-b)' => 'AI-204',
        'ai for professional' => 'AIP-101',
        'ai for children + python' => 'AIC-101',
        'gen a.i 6-months' => 'GAI-601',
        'web development (level-1 part-b)' => 'WD-102',
        'web development (level-2 part-a)' => 'WD-201',
        'graphic designing (level-1 part-a)' => 'GD-102',
        'graphic designing (level-1 part-b)' => 'GD-103',
        'graphic designing (level-2 part-a)' => 'GD-201',
        'graphic designing (level-2 part-b)' => 'GD-202',
        'shopify (level-1 part a)' => 'SHOP-102',
        'shopify (level-1 part-b)' => 'SHOP-103',
        'devops' => 'DO-101',
        'cyber security' => 'CS-101',
        'cyber security (3-months)' => 'CS-102',
        'cyber security (level-1 part-b)' => 'CS-103',
        'ui/ux designing' => 'UX-101',
        'chinese language' => 'CHI-101',
        'public speaking' => 'PS-101',

        // ---------------------------------------------------------------
        // The August 2026 intake sheet, which names a course's LENGTH.
        // ---------------------------------------------------------------
        //
        // `August Enrollment .xlsx` is hand-kept, not exported, and carries a
        // DURATION column the export tool never had. `RollReader::courseText()`
        // joins the two into "Course (Duration)" — the shape this table already
        // used for 'cyber security (3-months)' — so the pair is resolved here
        // rather than by a rule buried in the reader.
        //
        // The duration is NOT decoration. Two students bought "Digital Media
        // Marketing" that month, one for one month at 20,000 and one for three
        // at 60,000; on the name alone they are one course and one of them is
        // billed and rostered wrongly.
        //
        // The hyphen is normalised to a space before the lookup, because the
        // sheet writes "2-Months" on one line and "2 Months" on the next. That
        // is why these keys read "(3 months)" while the export's older key above
        // reads "(3-months)"; both are kept, both are exact, neither guesses.
        'graphic designing (3 months)' => 'GD-101',
        'odoo (2 months)' => 'ODOO-301',

        // Existing courses, chosen by length. CS-101 is the institute's plain
        // "Cyber Security" and CS-102 is its three-month one, so the sheet's two
        // Cyber Security rows are two different products and land on two codes.
        'cyber security (2 months)' => 'CS-101',
        'cyber security (3 months)' => 'CS-102',

        // The five created by
        // 2026_09_04_000001_add_the_five_courses_the_august_intake_sells.
        // "Kids Camp (Installment)" keeps its parenthetical because that is what
        // the sheet says; the word describes how it was PAID for, not a course
        // called Installment, and the fee it resolves to is the same either way.
        'kids camp (installment) (2 months)' => 'KC-M2',
        'gen a.i (3 months)' => 'GAI-M3',
        'digital media marketing (1 month)' => 'DMM-M1',
        'digital media marketing (3 months)' => 'DMM-M3',
        'e-commerce track (4 months)' => 'ECT-M4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone numbers a person has identified, and the normaliser cannot
    |--------------------------------------------------------------------------
    |
    | digits as written => the number in a form `Contact::normalizePhone()` can
    | read. Keyed on the digits alone, so one entry covers every spacing.
    |
    | This table is deliberately tiny and deliberately manual. The intake sheet
    | drops the leading symbol from every number it holds: "3214119234" is a PK
    | mobile missing its 0, and the normaliser already recovers those on its own
    | because ten digits beginning with 3 can only be one thing.
    |
    | A foreign number cannot be recovered that way, and must not be guessed —
    | `Contact::normalizePhone()` requires a leading "+" for exactly this reason,
    | so that a PK mobile typed one digit short stays unusable instead of being
    | silently accepted as somewhere else's. Adding a line here is a person
    | saying they know which country a number is in.
    |
    */
    'phone_corrections' => [
        // Emaad Hassan and Aalyan Aftab, August intake lines 11 and 12. Eleven
        // digits opening with 1 and a US area code; confirmed with the institute
        // as a genuine +1 number rather than a mistyped PK one.
        '16785495919' => '+1 678 549 5919',
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

        // The six officers created by
        // 2026_08_11_000002_create_accounts_for_the_officers_named_in_the_roll.
        // Those accounts are inactive by design; attribution needs the row, not
        // a live session, so the import works before anyone activates them.
        'sofia' => 'sofia',
        'mariyam' => 'mariyam',
        'shumail altaf' => 'shumailaltaf',
        'eman ashraf' => 'emanashraf',
        'iqra ijaz' => 'iqraijaz',
        'ayaan ali' => 'ayaanali',
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
