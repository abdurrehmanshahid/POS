<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creating batches and getting students into them.
 *
 * Two directions, which is what makes assignment feel automatic rather than
 * like a second form to remember:
 *
 *   forward   a new admission joins whichever cohort of its course is open, at
 *             the moment it is created (see {@see openFor()}).
 *
 *   backward  opening a cohort adopts every existing non-cancelled admission on
 *             that course that has no batch yet, so declaring "these students
 *             are Batch # 11" does not mean editing them one at a time.
 *
 * Only one cohort per course is open at a time. Two open intakes would make
 * "which batch does this enrolment join?" ambiguous, and the answer would
 * silently depend on row order.
 */
class Cohorts
{
    /**
     * @param  array{name:string,course_id:int,starts_on?:?string,ends_on?:?string,capacity?:?int,is_open?:bool}  $data
     */
    public function create(User $actor, array $data): Cohort
    {
        $course = Course::findOrFail($data['course_id']);
        $name = trim($data['name']);

        if ($name === '') {
            throw new RuntimeException('A batch name is required.');
        }
        if (Cohort::where('course_id', $course->id)->where('name', $name)->exists()) {
            throw new RuntimeException($course->code.' already has a batch called '.$name.'.');
        }

        return DB::transaction(function () use ($actor, $course, $name, $data) {
            $open = $data['is_open'] ?? true;

            $cohort = Cohort::create([
                'name' => $name,
                'course_id' => $course->id,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'is_open' => false,   // set via open() below so the rule lives in one place
                'created_by' => $actor->id,
            ]);

            Audit::record('Batch created', $actor, [
                'subject' => $cohort,
                'subject_label' => $cohort->name.' · '.$course->code,
                'new_value' => $cohort->name,
            ]);

            if ($open) {
                $this->open($cohort, $actor);
            }

            return $cohort->refresh();
        });
    }

    /**
     * Make this the intake for its course, closing whichever one was open, and
     * adopt the course's unbatched students.
     *
     * @return int how many existing admissions were pulled in
     */
    public function open(Cohort $cohort, User $actor): int
    {
        return DB::transaction(function () use ($cohort, $actor) {
            Cohort::where('course_id', $cohort->course_id)
                ->whereKeyNot($cohort->id)
                ->where('is_open', true)
                ->update(['is_open' => false]);

            $cohort->update(['is_open' => true]);

            $adopted = $this->adoptUnbatched($cohort, $actor);

            Audit::record('Batch opened', $actor, [
                'subject' => $cohort,
                'subject_label' => $cohort->label(),
                'field' => 'is_open',
                'old_value' => '0',
                'new_value' => '1',
                'context' => ['adopted_admissions' => $adopted],
            ]);

            return $adopted;
        });
    }

    public function close(Cohort $cohort, User $actor): void
    {
        $cohort->update(['is_open' => false]);

        Audit::record('Batch closed', $actor, [
            'subject' => $cohort,
            'subject_label' => $cohort->label(),
            'field' => 'is_open',
            'old_value' => '1',
            'new_value' => '0',
        ]);
    }

    /**
     * Every live admission on this cohort's course that has no batch becomes a
     * member. Deliberately does NOT steal admissions already in another batch:
     * a student who finished Batch # 10 is not retroactively in Batch # 11.
     */
    public function adoptUnbatched(Cohort $cohort, User $actor): int
    {
        $ids = Admission::where('course_id', $cohort->course_id)
            ->whereNull('cohort_id')
            ->where('status', '!=', 'cancelled')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Admission::whereIn('id', $ids)->update(['cohort_id' => $cohort->id]);

        return $ids->count();
    }

    /** The cohort a new admission on this course should join, if any. */
    public function openFor(int $courseId): ?Cohort
    {
        return Cohort::where('course_id', $courseId)->open()->first();
    }

    /** Move one enrolment between batches, e.g. a student deferring an intake. */
    public function assign(Admission $admission, ?Cohort $cohort, User $actor): void
    {
        if ($cohort && $cohort->course_id !== $admission->course_id) {
            throw new RuntimeException('That batch belongs to a different course.');
        }

        $before = $admission->cohort?->name ?? 'none';
        $admission->update(['cohort_id' => $cohort?->id]);

        Audit::record('Batch changed', $actor, [
            'subject' => $admission->challan,
            'subject_label' => $admission->reg_no,
            'field' => 'cohort',
            'old_value' => $before,
            'new_value' => $cohort?->name ?? 'none',
        ]);
    }
}
