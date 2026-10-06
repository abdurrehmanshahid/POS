<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\Student;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Freezing a student: pausing their studies and picking them up again later.
 *
 * Nothing is cancelled or re-billed. A frozen student keeps every enrolment
 * and every fee exactly as it was; they only drop off the attendance register
 * so they are not marked absent for weeks they were never expected to attend.
 *
 * The one thing that changes is time. When they are unfrozen, every unpaid
 * deadline that had not yet passed on the day they froze moves forward by the
 * number of days they were frozen, so they come back with the same runway they
 * left with. A deadline already missed before the freeze stays missed: the
 * freeze pauses the clock, it does not forgive what was overdue.
 *
 * Works for either actor: a staff User on the Students screen, or a SuperAdmin
 * on the super admin directory. Both land in the audit log by name.
 */
class StudentFreezes
{
    public function freeze(Model $actor, Student $student, string $reason): Student
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give a reason for freezing this student.');
        }
        if (mb_strlen($reason) > 255) {
            throw new RuntimeException('Keep the reason under 255 characters.');
        }

        return DB::transaction(function () use ($actor, $student, $reason) {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);

            if ($student->isFrozen()) {
                throw new RuntimeException($student->name.' is already frozen.');
            }

            $student->forceFill(['frozen_at' => Clock::now(), 'freeze_reason' => $reason])->save();

            Audit::record('Student frozen', $actor, [
                'subject' => $student,
                'subject_label' => $student->codeLabel().' · '.$student->name,
                'field' => 'status',
                'old_value' => 'active',
                'new_value' => 'frozen',
                'context' => ['reason' => $reason],
            ]);

            return $student;
        });
    }

    /**
     * @return array{student: Student, days: int, moved: int}  `moved` counts the deadlines pushed forward
     */
    public function unfreeze(Model $actor, Student $student): array
    {
        return DB::transaction(function () use ($actor, $student) {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);

            if (! $student->isFrozen()) {
                throw new RuntimeException($student->name.' is not frozen.');
            }

            // As a floating date on the same calendar Clock::today() uses, the
            // one every due_date is compared against, so the count is whole
            // days and not skewed by the timezone offset.
            $frozeOn = Carbon::parse($student->frozen_at->toDateString());
            $days = (int) max(0, round($frozeOn->diffInDays(Clock::today())));
            $moved = $days > 0 ? $this->moveDeadlines($student, $frozeOn->toDateString(), $days) : [];

            $since = $student->frozen_at;
            $student->forceFill(['frozen_at' => null, 'freeze_reason' => null])->save();

            Audit::record('Student unfrozen', $actor, [
                'subject' => $student,
                'subject_label' => $student->codeLabel().' · '.$student->name,
                'field' => 'status',
                'old_value' => 'frozen',
                'new_value' => 'active',
                'context' => [
                    'frozen_since' => Clock::local($since)->toDateString(),
                    'days_frozen' => $days,
                    'deadlines_moved' => $moved,
                ],
            ]);

            return ['student' => $student, 'days' => $days, 'moved' => count($moved)];
        });
    }

    /**
     * Push every unpaid deadline on or after $from forward by $days.
     *
     * The challan's own `due_date` follows: on a split plan it tracks the last
     * instalment (see Installments::schedule), so it is re-derived from the
     * schedule rather than shifted separately and allowed to drift.
     *
     * @return list<array{challan:string, installment:int|null, from:string, to:string}>
     */
    private function moveDeadlines(Student $student, string $from, int $days): array
    {
        $moved = [];

        $challans = Challan::query()
            ->where('student_id', $student->id)
            ->where('status', '!=', 'paid')
            // A cancelled enrolment's fee is not something they are coming back to.
            ->where(fn ($q) => $q->whereNull('admission_id')
                ->orWhereHas('admission', fn ($a) => $a->where('status', '!=', 'cancelled')))
            ->with('installments')
            ->lockForUpdate()
            ->get();

        foreach ($challans as $challan) {
            if ($challan->installments->isNotEmpty()) {
                foreach ($challan->installments as $installment) {
                    if ($installment->status === 'paid' || $installment->due_date === null
                        || $installment->due_date->toDateString() < $from) {
                        continue;
                    }
                    $old = $installment->due_date->toDateString();
                    $installment->update(['due_date' => $installment->due_date->copy()->addDays($days)]);
                    $moved[] = ['challan' => $challan->challan_no, 'installment' => $installment->seq,
                        'from' => $old, 'to' => $installment->due_date->toDateString()];
                }

                $last = $challan->installments->max(fn ($i) => $i->due_date?->toDateString());
                if ($last !== null && $last !== $challan->due_date?->toDateString()) {
                    $challan->update(['due_date' => $last]);
                }

                continue;
            }

            if ($challan->due_date !== null && $challan->due_date->toDateString() >= $from) {
                $old = $challan->due_date->toDateString();
                $challan->update(['due_date' => $challan->due_date->copy()->addDays($days)]);
                $moved[] = ['challan' => $challan->challan_no, 'installment' => null,
                    'from' => $old, 'to' => $challan->due_date->toDateString()];
            }
        }

        return $moved;
    }
}
