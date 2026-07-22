<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The registration wizard submit (spec §7.5). Multi-course fan-out: one
 * admission AND one challan per selected course, so each enrolment stays
 * independently auditable. Money is always derived; discounts require a reason.
 */
class RegistrationService
{
    public function __construct(private Sequences $sequences) {}

    /**
     * @param  array{
     *   student_id?:int|null,
     *   new_student?:array{type:string,name:string,guardian_name:string,phone:string,cnic:string}|null,
     *   course_ids:array<int>,
     *   discount_pct?:int,
     *   discount_reason?:string|null
     * }  $data
     * @return array{student:Student, admissions:list<Admission>, challans:list<Challan>}
     */
    public function register(User $actor, array $data): array
    {
        $pct = (int) ($data['discount_pct'] ?? 0);
        $reason = trim((string) ($data['discount_reason'] ?? '')) ?: null;
        $courseIds = array_values(array_unique($data['course_ids'] ?? []));

        if ($courseIds === []) {
            throw new InvalidArgumentException('Select at least one course.');
        }
        if ($pct > 0 && $reason === null) {
            throw new InvalidArgumentException('A discount requires a reason.');
        }

        return DB::transaction(function () use ($actor, $data, $courseIds, $pct, $reason) {
            $student = $this->resolveStudent($actor, $data);

            $courses = Course::query()->whereIn('id', $courseIds)->get();
            $due = Clock::today()->copy()->addDays(7)->toDateString();

            $admissions = [];
            $challans = [];

            foreach ($courses as $course) {
                $admission = Admission::create([
                    'reg_no' => $this->sequences->nextAdmissionNo(),
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                    'enrolled_by' => $actor->id,
                    'status' => 'validated',
                ]);

                $base = (int) $course->fee;
                $discount = $pct > 0 ? (int) round($base * $pct / 100) : 0;

                $challan = Challan::create([
                    'challan_no' => $this->sequences->nextChallanNo(),
                    'admission_id' => $admission->id,
                    'base_amount' => $base,
                    'discount_amount' => $discount,
                    'discount_reason' => $discount > 0 ? $reason : null,
                    'discount_approved_by' => $discount > 0 ? $actor->id : null,
                    'net_amount' => $base - $discount,
                    'plan' => 'full',
                    'due_date' => $due,
                    'status' => 'unpaid',
                ]);

                Audit::issued($challan, $actor);
                if ($discount > 0) {
                    Audit::discountApplied($challan, $actor);
                }

                $admissions[] = $admission;
                $challans[] = $challan;
            }

            AppNotification::create([
                'type' => 'enrol',
                'title' => 'New enrolment created',
                'sub' => $student->name.', '.count($courses).' course'.(count($courses) === 1 ? '' : 's'),
                'student_id' => $student->id,
                'challan_id' => $challans[0]->id ?? null,
            ]);

            return ['student' => $student, 'admissions' => $admissions, 'challans' => $challans];
        });
    }

    private function resolveStudent(User $actor, array $data): Student
    {
        if (! empty($data['student_id'])) {
            return Student::query()->findOrFail($data['student_id']);
        }

        $new = $data['new_student'] ?? null;
        if (! $new) {
            throw new InvalidArgumentException('No student provided.');
        }

        $type = $new['type'] === 'T' ? 'T' : 'R';

        return Student::create([
            'student_code' => $this->sequences->nextStudentCode($type),
            'type' => $type,
            'name' => $new['name'],
            'guardian_name' => $new['guardian_name'],
            'cnic' => $new['cnic'],
            'phone' => $new['phone'],
            'created_by' => $actor->id,
        ]);
    }
}
