<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking the register.
 *
 * The `attendances` table and its model shipped with the original schema and
 * then sat inert: nothing read it, nothing wrote it, and the Reports screen
 * rendered invented 92% and 78% figures against it until those were deleted for
 * being fiction. This class is the writer that was missing.
 *
 * Two decisions shape it:
 *
 *  1. **The roster comes from admissions, not from a class list.** Who is in a
 *     class is already answered by "who has a live enrolment on this course",
 *     so there is nothing to keep in sync. A cancelled enrolment drops out of
 *     the register the same day it is cancelled.
 *
 *  2. **Saving a register is idempotent.** Marks are keyed on
 *     (course, student, date), so re-saving corrects the day rather than
 *     recording it twice. The unique index added alongside this class is what
 *     makes that a guarantee rather than a hope.
 */
class Attendances
{
    public const STATUSES = ['present', 'absent', 'leave'];

    /**
     * The students who should be marked for this course on this day.
     *
     * Ordered by student code so the register reads in the same order every
     * time, which is what makes marking a class of thirty by eye possible.
     *
     * @return Collection<int, Student>
     */
    public function roster(Course $course, ?Cohort $cohort = null): Collection
    {
        $studentIds = Admission::query()
            ->where('course_id', $course->id)
            ->where('status', '!=', 'cancelled')
            ->when($cohort, fn ($q) => $q->where('cohort_id', $cohort->id))
            ->pluck('student_id')
            ->unique();

        return Student::query()
            ->whereIn('id', $studentIds)
            // A frozen student is on hold, not absent. They return to the
            // register when they are unfrozen.
            ->notFrozen()
            ->orderBy('student_code')
            ->get();
    }

    /**
     * Marks already recorded for a course on a day, keyed by student id.
     *
     * @return array<int, string>
     */
    public function marksFor(Course $course, string $date): array
    {
        return Attendance::query()
            ->where('course_id', $course->id)
            ->whereDate('session_date', $date)
            ->pluck('status', 'student_id')
            ->map(fn ($s) => (string) $s)
            ->all();
    }

    /**
     * Record (or correct) one day's register.
     *
     * @param  array<int, string>  $marks  student id => present|absent|leave
     * @return int how many marks were written
     *
     * @throws RuntimeException
     */
    public function record(User $actor, Course $course, ?Cohort $cohort, string $date, array $marks): int
    {
        $day = Carbon::parse($date)->startOfDay();

        // A register for a day that has not happened is not a record of
        // anything. Marking forward is how "everyone present" gets pre-filled
        // for a month and the figures stop meaning attendance.
        if ($day->isAfter(Clock::today())) {
            throw new RuntimeException('You cannot take the register for a future date.');
        }

        $roster = $this->roster($course, $cohort)->keyBy('id');

        // Only students actually on this course may be marked, whatever the
        // client sent.
        $clean = [];
        foreach ($marks as $studentId => $status) {
            $studentId = (int) $studentId;

            if (! $roster->has($studentId)) {
                continue;
            }
            if (! in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Unknown attendance status: '.$status);
            }

            $clean[$studentId] = $status;
        }

        if ($clean === []) {
            throw new RuntimeException('Nobody on this register was marked.');
        }

        return DB::transaction(function () use ($actor, $course, $cohort, $day, $clean) {
            $before = $this->marksFor($course, $day->toDateString());

            foreach ($clean as $studentId => $status) {
                // Matched with whereDate rather than updateOrCreate.
                //
                // `session_date` is a date column but Eloquent writes it through
                // the connection's datetime format, so the stored value is
                // "2026-07-15 00:00:00". An equality match on "2026-07-15" finds
                // nothing, so updateOrCreate re-inserted and hit the unique index
                // instead of correcting the mark.
                $existing = Attendance::query()
                    ->where('course_id', $course->id)
                    ->where('student_id', $studentId)
                    ->whereDate('session_date', $day->toDateString())
                    ->first();

                $attributes = [
                    'cohort_id' => $cohort?->id,
                    'status' => $status,
                    'marked_by' => $actor->id,
                ];

                if ($existing) {
                    $existing->update($attributes);

                    continue;
                }

                Attendance::create($attributes + [
                    'course_id' => $course->id,
                    'student_id' => $studentId,
                    'session_date' => $day,
                ]);
            }

            $counts = array_count_values($clean);

            // One audit row per register, not per student. Thirty rows a day
            // would bury every other event in the activity log, and the day is
            // the unit an operator actually asks about.
            Audit::record($before === [] ? 'Attendance recorded' : 'Attendance corrected', $actor, [
                'subject' => $course,
                'subject_label' => $course->code.' · '.$day->format('d M Y')
                    .($cohort ? ' · '.$cohort->name : ''),
                'field' => 'attendance',
                'old_value' => $before === [] ? 'not taken' : count($before).' marked',
                'new_value' => count($clean).' marked',
                'context' => [
                    'session_date' => $day->toDateString(),
                    'cohort' => $cohort?->name,
                    'present' => $counts['present'] ?? 0,
                    'absent' => $counts['absent'] ?? 0,
                    'leave' => $counts['leave'] ?? 0,
                ],
            ]);

            return count($clean);
        });
    }

    /**
     * Attendance rate per course over a window, best first.
     *
     * `leave` counts as neither present nor absent: authorised absence should
     * not punish a course's rate, so it is excluded from the denominator
     * entirely rather than counted as a half.
     *
     * @return Collection<int, object{code:string,title:string,present:int,absent:int,leave:int,rate:int|null}>
     */
    public function ratesByCourse(Carbon $from, Carbon $to): Collection
    {
        return Attendance::query()
            ->join('courses', 'courses.id', '=', 'attendances.course_id')
            ->whereBetween('attendances.session_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('courses.id', 'courses.code', 'courses.title')
            ->select([
                'courses.code', 'courses.title',
                DB::raw("SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present"),
                DB::raw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent"),
                DB::raw("SUM(CASE WHEN attendances.status = 'leave' THEN 1 ELSE 0 END) as \"leave\""),
            ])
            ->get()
            ->map(function ($r) {
                $present = (int) $r->present;
                $absent = (int) $r->absent;
                $counted = $present + $absent;

                return (object) [
                    'code' => $r->code,
                    'title' => $r->title,
                    'present' => $present,
                    'absent' => $absent,
                    'leave' => (int) $r->leave,
                    // Null, not zero, when nothing countable was recorded. A
                    // course with no register taken has no attendance rate, and
                    // showing 0% would read as "nobody turned up".
                    'rate' => $counted > 0 ? (int) round($present / $counted * 100) : null,
                ];
            })
            ->sortByDesc(fn ($r) => $r->rate ?? -1)
            ->values();
    }
}
