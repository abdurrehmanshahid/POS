<?php

namespace Database\Seeders;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Counter;
use App\Models\Course;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit;
use App\Services\Sequences;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Reproduces the prototype seed exactly (spec §14) so that, given the same seed
 * and actions, this system yields the same IDs, statuses, audit trails and KPIs.
 * Admin-scope parity anchors: billed Rs 214,000 · received Rs 119,000 (56%) ·
 * outstanding Rs 95,000 · 11 challans · 10 active students · 3 July regs.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Settings (§14.5), serial is the atomic challan counter -------
        Setting::query()->create([
            'name' => 'Big Binary Tech Institute',
            'bank' => 'Meezan Bank Ltd',
            'account' => '0102-0104-567890',
            'iban' => 'PK36 MEZN 0001 0201 0456 7890',
            'next_challan_serial' => 1086,
            'twofa_required' => true,
        ]);

        // ---- Users (§4.2), seeded users skip the first-login reset ---------
        $users = [];
        foreach ([
            ['Ansar Ali', 'adminansar', 'ansar@bbt.edu.pk', 'Bbt@Admin1', 'admin'],
            ['Ali Raza', 'aliraza', 'ali.raza@bbt.edu.pk', 'Bbt@Officer1', 'officer'],
            ['Fatima Noor', 'fatimanoor', 'fatima.noor@bbt.edu.pk', 'Bbt@Officer2', 'officer'],
        ] as [$name, $username, $email, $password, $role]) {
            $users[] = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'role_id' => $role,
                'is_active' => true,
                'must_reset_password' => false,
            ]);
        }
        $admin = $users[0];

        // ---- Teachers (§2.6) -----------------------------------------------
        $teachers = [];
        foreach (['Umer Ali', 'Raja Shahzaib', 'Zubair Munir', 'Hassan', 'Big Binary Tech'] as $name) {
            $teachers[$name] = Teacher::create(['name' => $name, 'created_at' => now()]);
        }

        // ---- Courses (§14.1), [code, title, trainer, fee, capacity, active]
        $courses = [];
        foreach ([
            ['WD-101', 'Web Development (Level-1 Part-A)', 'Umer Ali', 20000, 20, true],
            ['AI-201', 'Artificial Intelligence (Level-1 Part-A)', 'Umer Ali', 20000, 25, true],
            ['DMM-101', 'Digital Media Marketing (Level-1 Part-A)', 'Raja Shahzaib', 12000, 30, true],
            ['SHOP-101', 'Shopify', 'Zubair Munir', 25000, 15, true],
            ['GD-101', 'Graphic Designing', 'Zubair Munir', 20000, 20, true],
            ['SKC-101', 'Super Kid Camp', 'Hassan', 15000, null, true],
            ['ODOO-301', 'Odoo ERP Development', 'Big Binary Tech', 80000, 10, true],
            ['VE-101', 'Video Editing and YouTube Automation', 'Hassan', 25000, 18, true],
            ['ROB-101', 'STEM Robotics', 'Hassan', 15000, 16, false],
        ] as [$code, $title, $trainer, $fee, $cap, $active]) {
            $courses[$code] = Course::create([
                'code' => $code,
                'title' => $title,
                'trainer_id' => $teachers[$trainer]->id,
                'fee' => $fee,
                'capacity' => $cap,
                'is_active' => $active,
            ]);
        }

        // ---- Students (§14.2), 1-indexed; admissions reference by index ----
        $students = [];
        $studentRows = [
            1 => ['R26-0009', 'R', 'Maha Asim', '35201-1234567-1', 'Asim Raza', '+92 300 1234567', '2026-07-02'],
            2 => ['T26-0002', 'T', 'Umer Hayat', '35202-2345678-3', 'Hayat Ullah', '+92 301 2345678', '2026-07-03'],
            3 => ['R26-0008', 'R', 'Hafiz M Ayaan Asif', '35202-3456789-5', 'Asif Mahmood', '+92 321 3456789', '2026-06-29'],
            4 => ['R26-0007', 'R', 'Syeda Sana', '35201-4567890-2', 'Syed Kamran', '+92 333 4567890', '2026-06-29'],
            5 => ['R26-0006', 'R', 'Qudamah Salman', '35202-5678901-7', 'Salman Akhtar', '+92 300 5678901', '2026-06-27'],
            6 => ['R26-0005', 'R', 'Maham Tariq', '35201-6789012-4', 'Tariq Javed', '+92 345 6789012', '2026-06-27'],
            7 => ['T26-0001', 'T', 'Abdur Rehman', '35202-7890123-9', 'Rehman Ali', '+92 311 7890123', '2026-06-24'],
            8 => ['R26-0004', 'R', 'Mohsin Iqbal', '35201-8901234-1', 'Iqbal Hussain', '+92 300 8901234', '2026-06-24'],
            9 => ['R26-0003', 'R', 'Muhammad Huzaifa', '35202-9012345-6', 'Zahid Mehmood', '+92 322 9012345', '2026-06-18'],
            10 => ['R26-0002', 'R', 'Rabia Bano', '35201-0123456-8', 'Anwar Bano', '+92 333 0123456', '2026-06-18'],
            11 => ['R26-0001', 'R', 'Ahmad Ameer Sani', '35202-1122334-2', 'Ameer Sani', '+92 300 1122334', '2026-06-13'],
            12 => ['R26-0010', 'R', 'Yasmeen Rafique', '35201-2233445-5', 'Rafique Ahmed', '+92 345 2233445', '2026-06-13'],
        ];
        // The literals above are the bare series, so the tables stay readable.
        // The institute prefix is applied on write from the same config the
        // Sequences service reads, so seeded and generated codes cannot drift.
        $prefix = Sequences::prefix();

        foreach ($studentRows as $i => [$code, $type, $name, $cnic, $guardian, $phone, $joined]) {
            $s = Student::create([
                'student_code' => $prefix.$code,
                'type' => $type,
                'name' => $name,
                'guardian_name' => $guardian,
                'cnic' => $cnic,
                'phone' => $phone,
                'created_by' => $admin->id,
            ]);
            $this->backdate('students', $s->id, $joined);
            $students[$i] = $s;
        }

        // ---- Admissions + challans (§14.3) ---------------------------------
        // [reg_no, studentIdx, courseCode, at, challan_no, base, disc, reason,
        //  due, status, paidAt, byUserId, paidVia]
        $rows = [
            ['ADM-0011', 2, 'ODOO-301', '2026-07-03', 'CH-2026-1085', 80000, 40000, 'Alumni referral, 50% partial scholarship', '2026-07-10', 'paid', '2026-07-03', 2, 'Bank transfer'],
            ['ADM-0010', 1, 'AI-201', '2026-07-02', 'CH-2026-1084', 20000, 0, null, '2026-07-16', 'unpaid', null, 2, null],
            ['ADM-0009', 1, 'DMM-101', '2026-07-02', 'CH-2026-1083', 12000, 4000, 'Sibling discount', '2026-07-18', 'unpaid', null, 3, null],
            ['ADM-0008', 3, 'SHOP-101', '2026-06-29', 'CH-2026-1082', 25000, 0, null, '2026-07-06', 'paid', '2026-06-29', 2, 'Cash'],
            ['ADM-0007', 4, 'DMM-101', '2026-06-29', 'CH-2026-1081', 12000, 0, null, '2026-07-06', 'paid', '2026-06-29', 1, 'Cash'],
            ['ADM-0006', 5, 'VE-101', '2026-06-27', 'CH-2026-1080', 25000, 0, null, '2026-07-04', 'unpaid', null, 2, null],
            ['ADM-0005', 6, 'DMM-101', '2026-06-27', 'CH-2026-1079', 12000, 0, null, '2026-07-04', 'paid', '2026-06-27', 3, 'Card'],
            ['ADM-0004', 7, 'SKC-101', '2026-06-24', 'CH-2026-1078', 15000, 5000, 'Early bird camp promo', '2026-07-01', 'paid', '2026-06-24', 2, 'Cash'],
            ['ADM-0003', 8, 'AI-201', '2026-06-24', 'CH-2026-1077', 20000, 3000, 'Merit, top of entry test', '2026-07-01', 'unpaid', null, 3, null],
            ['ADM-0002', 9, 'SHOP-101', '2026-06-18', 'CH-2026-1076', 25000, 0, null, '2026-06-25', 'unpaid', null, 1, null],
            ['ADM-0001', 10, 'GD-101', '2026-06-18', 'CH-2026-1075', 20000, 0, null, '2026-06-25', 'paid', '2026-06-18', 2, 'Bank transfer'],
        ];

        foreach ($rows as [$regNo, $sIdx, $code, $at, $chNo, $base, $disc, $reason, $due, $status, $paidAt, $by, $via]) {
            $atDate = Carbon::parse($at);

            $admission = Admission::create([
                'reg_no' => $prefix.$regNo,
                'student_id' => $students[$sIdx]->id,
                'course_id' => $courses[$code]->id,
                'enrolled_by' => $by,
                'status' => 'validated',
            ]);
            $this->backdate('admissions', $admission->id, $at);

            $challan = Challan::create([
                'challan_no' => $prefix.$chNo,
                'admission_id' => $admission->id,
                'base_amount' => $base,
                'discount_amount' => $disc,
                'discount_reason' => $disc > 0 ? $reason : null,
                'discount_approved_by' => $disc > 0 ? $admin->id : null,
                'net_amount' => $base - $disc,
                'plan' => 'full',
                'due_date' => $due,
                'status' => $status,
                'paid_via' => $via,
                'paid_at' => $paidAt ? Carbon::parse($paidAt.' 12:00:00') : null,
            ]);
            $this->backdate('challans', $challan->id, $at);

            // Immutable audit trail (§2.10): issued, discount (if any), paid (if paid).
            $enroller = $users[$by - 1];
            Audit::issued($challan, $enroller, $atDate);
            if ($disc > 0) {
                Audit::discountApplied($challan, $admin, $atDate);
            }
            if ($status === 'paid') {
                Audit::markedPaid($challan, $enroller, $via, Carbon::parse($paidAt.' 12:00:00'));

                // Collections are their own rows now, and Ledger::received()
                // sums them. A challan flagged paid with no payment row would
                // read as billed-but-never-collected.
                $challan->payments()->create([
                    'amount' => $challan->net_amount,
                    'method' => $via,
                    'received_by' => $enroller->id,
                    'received_at' => Carbon::parse($paidAt.' 12:00:00'),
                ]);
            }
        }

        // ---- Batches -------------------------------------------------------
        // One open intake per course that is actually running, so the demo shows
        // a populated "Batch #" on the challan rather than a dash. Written
        // directly rather than through the Cohorts service: the service audits
        // every open, and seed data is history, not activity.
        foreach ([
            ['SHOP-101', 'Batch # 11', '2026-06-18'],
            ['ODOO-301', 'Batch # 3', '2026-07-03'],
            ['AI-201', 'Batch # 6', '2026-06-24'],
            ['DMM-101', 'Batch # 9', '2026-06-27'],
            ['WD-101', 'Batch # 4', '2026-07-01'],
        ] as [$code, $batch, $startsOn]) {
            $cohort = Cohort::create([
                'name' => $batch,
                'course_id' => $courses[$code]->id,
                'starts_on' => $startsOn,
                'capacity' => $courses[$code]->capacity,
                'is_open' => true,
                'created_by' => $admin->id,
            ]);

            Admission::where('course_id', $courses[$code]->id)
                ->where('status', '!=', 'cancelled')
                ->update(['cohort_id' => $cohort->id]);
        }

        // ---- Counters after seed (§14.4), the NEXT value to assign --------
        Counter::insert([
            ['key' => 'student:R', 'value' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'student:T', 'value' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'admission', 'value' => 12, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ---- Notifications (§10) -------------------------------------------
        $chOf = fn (string $no) => Challan::where('challan_no', $no)->value('id');
        AppNotification::create([
            'type' => 'overdue', 'title' => 'Payment overdue',
            'sub' => 'Muhammad Huzaifa · CH-2026-1076',
            'student_id' => $students[9]->id, 'challan_id' => $chOf('CH-2026-1076'),
            'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        AppNotification::create([
            'type' => 'enrol', 'title' => 'New enrolment created',
            'sub' => 'Maha Asim · Artificial Intelligence',
            'student_id' => $students[1]->id, 'challan_id' => $chOf('CH-2026-1084'),
            'created_at' => now()->subHours(6), 'updated_at' => now()->subHours(6),
        ]);
        AppNotification::create([
            'type' => 'payment', 'title' => 'Payment recorded',
            'sub' => 'Abdur Rehman · Rs 10,000 (Card)',
            'student_id' => $students[7]->id, 'challan_id' => $chOf('CH-2026-1078'),
            'is_revenue' => true, 'read_at' => now()->subDay(),
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        AppNotification::create([
            'type' => 'capacity', 'title' => 'Seats running low',
            'sub' => 'Odoo ERP Development · 9 of 10 filled',
            'is_admin' => true,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    /** Backdate created_at/updated_at without Eloquent overriding them. */
    private function backdate(string $table, int $id, string $date): void
    {
        $ts = Carbon::parse($date.' 09:00:00');
        DB::table($table)->where('id', $id)->update(['created_at' => $ts, 'updated_at' => $ts]);
    }
}
