<?php

namespace App\Http\Controllers;

use App\Models\Student;
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

        $students = Student::visibleTo($user)
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

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="BBT Students.csv"',
        ];

        return response()->streamDownload(function () use ($students) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            $this->putRow($out, [
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

                $this->putRow($out, [
                    $s->student_code, $s->name, $s->typeLabel(), $s->guardian_name, $s->cnic,
                    $s->phone, Format::date($s->created_at), $enroller, $courses,
                    number_format($outstanding), $status,
                ]);
            }
            fclose($out);
        }, 'BBT Students.csv', $headers);
    }

    private function putRow($out, array $fields): void
    {
        // CRLF line endings with standard quoting.
        fwrite($out, $this->csvLine($fields)."\r\n");
    }

    private function csvLine(array $fields): string
    {
        return implode(',', array_map(function ($f) {
            $f = (string) $f;
            if (preg_match('/[",\r\n]/', $f)) {
                return '"'.str_replace('"', '""', $f).'"';
            }

            return $f;
        }, $fields));
    }
}
