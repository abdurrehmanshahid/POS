<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Support\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing student records outside the registration wizard.
 *
 * Why this exists: the wizard could only mint a student as a side effect of
 * enrolling them in a course, which meant a walk-in who has not chosen a course
 * yet could not be recorded at all, and a mistyped CNIC was permanent. The data
 * model always allowed a student with no admissions, the seed data even
 * contains one, so the gap was purely in the UI.
 *
 * Identity fields are treated as consequential, not cosmetic. A student's CNIC
 * and name are printed on issued challans and are how a person is identified at
 * the counter, so every edit writes an audit row recording the before and after.
 */
class StudentService
{
    public function __construct(private readonly Sequences $sequences) {}

    /**
     * Create a student record. No course, no admission, no challan, just the
     * person. The next code in the chosen series (R26-#### / T26-####) is
     * allocated atomically, exactly as the wizard does it.
     *
     * Type `W` records a walk-in instead, with a code from the walk-in series
     * (W26-####). It is replaced by an R or T code when they register
     * (RegistrationService::promoteIfWalkIn).
     *
     * @param  array{type:string,name:string,guardian_name:string,phone:string,cnic:string}  $data
     */
    public function create(User $actor, array $data): Student
    {
        $walkIn = ($data['type'] ?? null) === 'W';
        $clean = $this->validate($data);

        return DB::transaction(function () use ($actor, $clean, $walkIn) {
            $student = Student::create([
                'student_code' => $this->sequences->nextStudentCode($walkIn ? 'W' : $clean['type']),
                // A placeholder for a walk-in; it is overwritten on enrolment.
                'type' => $clean['type'],
                'kind' => $walkIn ? 'walkin' : 'student',
                'name' => $clean['name'],
                'guardian_name' => $clean['guardian_name'],
                'cnic' => $clean['cnic'],
                'phone' => $clean['phone'],
                'created_by' => $actor->id,
            ]);

            Audit::record($walkIn ? 'Walk-in recorded' : 'Student created', $actor, [
                'subject' => $student,
                'subject_label' => $student->codeLabel().' · '.$student->name,
                'new_value' => $student->codeLabel(),
            ]);

            return $student;
        });
    }

    /**
     * Update an existing student's identity details.
     *
     * `student_code` and `type` are deliberately NOT editable. The code is
     * already printed on issued challans and referenced in the audit trail;
     * letting it drift would make historical documents disagree with the
     * database. A student enrolled under the wrong series needs a new record,
     * not a rewritten one.
     *
     * @param  array{name:string,guardian_name:string,phone:string,cnic:string}  $data
     */
    public function update(User $actor, Student $student, array $data): Student
    {
        $clean = $this->validate($data + ['type' => $student->type], $student);

        return DB::transaction(function () use ($actor, $student, $clean) {
            $before = $student->only(['name', 'guardian_name', 'cnic', 'phone']);

            $student->update([
                'name' => $clean['name'],
                'guardian_name' => $clean['guardian_name'],
                'cnic' => $clean['cnic'],
                'phone' => $clean['phone'],
            ]);

            // One audit row per changed field, so the trail reads as a diff
            // rather than as an opaque "record was edited".
            foreach ($before as $field => $old) {
                if ($old !== $student->$field) {
                    Audit::record('Student details edited', $actor, [
                        'subject' => $student,
                        'subject_label' => $student->codeLabel().' · '.$student->name,
                        'field' => $field,
                        'old_value' => (string) $old,
                        'new_value' => (string) $student->$field,
                    ]);
                }
            }

            return $student->refresh();
        });
    }

    /**
     * Shared validation for both paths (spec §9.11 rules).
     *
     * Phone and CNIC are normalised here rather than trusted from the client,
     * so `+92 300 1234567`, `03001234567` and `92 300 1234567` all land in the
     * database in one canonical shape, which is what makes the uniqueness
     * check on CNIC and the search index meaningful.
     *
     * @param  array<string, mixed>  $data
     * @return array{type:string,name:string,guardian_name:?string,phone:string,cnic:?string}
     */
    private function validate(array $data, ?Student $existing = null): array
    {
        $errors = [];

        $type = ($data['type'] ?? 'R') === 'T' ? 'T' : 'R';
        $name = trim((string) ($data['name'] ?? ''));
        $guardian = trim((string) ($data['guardian_name'] ?? ''));
        $cnic = trim((string) ($data['cnic'] ?? ''));
        $phoneRaw = trim((string) ($data['phone'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Student name is required.';
        }

        // Guardian and CNIC are optional, matching the wizard and the schema
        // (2026_08_08_000001). They were required here alone, so a student the
        // wizard was allowed to create could never be saved from this form — the
        // two doors that write a student disagreed about what a student is.
        //
        // Optional is not the same as unchecked: a half-typed CNIC is not
        // omitted, it is wrong, and storing it would put a broken identity
        // number on an issued challan.
        if ($cnic !== '' && ! Contact::validCnic($cnic)) {
            $errors['cnic'] = 'CNIC must look like 35201-1234567-1, or leave it blank.';
        } elseif ($cnic !== '') {
            // Uniqueness includes soft-deleted rows: a CNIC belonging to a
            // removed student is still that person's, and silently reusing it
            // would merge two humans if the record is ever restored.
            $clash = Student::withTrashed()
                ->where('cnic', $cnic)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->first();

            if ($clash) {
                $errors['cnic'] = $clash->trashed()
                    ? 'That CNIC belongs to a removed student ('.$clash->codeLabel().'). Restore that record instead.'
                    : 'That CNIC is already registered to '.$clash->name.' ('.$clash->codeLabel().').';
            }
        }

        $phone = Contact::normalizePhone($phoneRaw);
        if ($phone === null) {
            $errors['phone'] = 'Enter a valid Pakistani mobile, e.g. 0300 1234567.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type' => $type,
            'name' => $name,
            'guardian_name' => Contact::optional($guardian),
            'cnic' => Contact::optional($cnic),
            'phone' => $phone,
        ];
    }
}
