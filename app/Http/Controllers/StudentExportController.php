<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Support\Csv;
use App\Support\Download;
use App\Support\Format;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Students → Excel/CSV export (spec §11). Scope-aware (officers export only
 * their own students and only their own enrolments count). UTF-8 with BOM,
 * CRLF line endings, standard quoting; filename "BBT Students.csv".
 */
class StudentExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        // The Walk-ins tab exports its own list; the Students tab leaves them out.
        $walkIns = $request->boolean('walkins');

        $students = Student::visibleTo($user)
            ->when($walkIns, fn ($q) => $q->walkIns(), fn ($q) => $q->notWalkIns())
            ->with(['admissions' => function ($q) use ($user) {
                $q->where('status', '!=', 'cancelled')
                    ->when(! $user->can('scope.all'), fn ($qq) => $qq->where('enrolled_by', $user->id))
                    // `challan.payments` is eager loaded so `balance()` below
                    // answers from memory instead of firing a query per row.
                    ->with(['course', 'enroller', 'challan.payments']);
            }])
            // Charges are not scoped down the way admissions are above: an
            // officer's own charges are already all `visibleTo` lets through,
            // and narrowing them again would drop a contact's balance from
            // their own row.
            ->withCharges()
            ->orderBy('student_code')
            ->get();

        $filename = $walkIns ? 'BBT Walk-ins.csv' : 'BBT Students.csv';

        $headers = ['Content-Type' => 'text/csv; charset=UTF-8'];

        // Named through the helper, which states the quoted and the RFC 5987
        // forms rather than relying on either being inferred. This filename has
        // a space in it, so an unquoted disposition cannot express it at all.
        return Download::named(response()->streamDownload(function () use ($students) {
            echo Csv::BOM;
            echo Csv::row([
                'Student ID', 'Name', 'Type', 'Guardian', 'CNIC', 'Phone', 'Joined',
                'Enrolled By', 'Courses', 'Outstanding (Rs)', 'Fee Status',
            ]);

            foreach ($students as $s) {
                $adm = $s->admissions;
                $courses = $adm->map(fn ($a) => trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $a->course->title)))
                    ->unique()->implode('; ');
                $enroller = optional($adm->sortByDesc('created_at')->first()?->enroller)->name ?? '';

                // Σ balance, not "net of the challans flagged paid". A student
                // who has handed over half their fee owes half; the old
                // arithmetic exported them as owing all of it, so this file
                // contradicted the student drawer sitting next to it on screen.
                $outstanding = $s->outstanding();

                $status = $adm->isEmpty() ? 'No enrolment' : ($outstanding <= 0 ? 'Cleared' : 'Owes');

                echo Csv::row([
                    $s->student_code, $s->name, $s->typeLabel(), $s->guardian_name, $s->cnic,
                    $s->phone, Format::date($s->created_at), $enroller, $courses,
                    number_format($outstanding), $status,
                ]);
            }
        }, $filename, $headers), $filename);
    }
}
