<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding and editing student records directly from the Students screen, without
 * having to enrol them in a course in the same breath.
 */
class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    public function test_a_student_can_be_created_without_any_course(): void
    {
        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('newStudent')
            ->set('fType', 'R')
            ->set('fName', 'Bilal Ahmed')
            ->set('fGuardian', 'Ahmed Khan')
            ->set('fPhone', '0300 7654321')
            ->set('fCnic', '35201-7654321-3')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $student = Student::where('cnic', '35201-7654321-3')->firstOrFail();

        $this->assertSame('Bilal Ahmed', $student->name);
        $this->assertSame('BBT-R26-0011', $student->student_code, 'Takes the next R serial.');
        $this->assertCount(0, $student->admissions, 'No course, no admission, no challan.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Student created', 'subject_id' => $student->id]);
    }

    public function test_the_track_series_is_independent(): void
    {
        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('newStudent')
            ->set('fType', 'T')
            ->set('fName', 'Track Kid')
            ->set('fGuardian', 'Guardian Name')
            ->set('fPhone', '0301 1112223')
            ->set('fCnic', '35202-1112223-4')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $this->assertSame('BBT-T26-0003', Student::where('cnic', '35202-1112223-4')->value('student_code'));
    }

    public function test_phone_is_normalised_and_cnic_format_is_enforced(): void
    {
        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('newStudent')
            ->set('fName', 'Format Test')
            ->set('fGuardian', 'Guardian')
            ->set('fPhone', '03009998887')      // no spaces, leading zero
            ->set('fCnic', '3520199988877')     // missing dashes
            ->call('saveStudent')
            ->assertHasErrors('fCnic');

        $this->assertDatabaseMissing('students', ['name' => 'Format Test']);

        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('newStudent')
            ->set('fName', 'Format Test')
            ->set('fGuardian', 'Guardian')
            ->set('fPhone', '03009998887')
            ->set('fCnic', '35201-9998887-7')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $this->assertSame('+92 300 9998887', Student::where('name', 'Format Test')->value('phone'));
    }

    public function test_duplicate_cnic_is_rejected(): void
    {
        $existing = Student::firstOrFail();

        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('newStudent')
            ->set('fName', 'Impostor')
            ->set('fGuardian', 'Guardian')
            ->set('fPhone', '0300 1231231')
            ->set('fCnic', $existing->cnic)
            ->call('saveStudent')
            ->assertHasErrors('fCnic');
    }

    public function test_officers_cannot_edit_student_identity(): void
    {
        $student = Student::firstOrFail();

        $this->assertFalse($this->officer()->can('students.manage'));

        Livewire::actingAs($this->officer())
            ->test('pages.students')
            ->call('editStudent', $student->id)
            ->assertStatus(403);
    }

    public function test_admin_can_edit_a_student_and_the_change_is_audited(): void
    {
        $student = Student::where('student_code', 'BBT-R26-0009')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('editStudent', $student->id)
            ->set('fName', 'Maha Asim Corrected')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $this->assertSame('Maha Asim Corrected', $student->fresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Student details edited',
            'subject_id' => $student->id,
            'field' => 'name',
            'old_value' => 'Maha Asim',
            'new_value' => 'Maha Asim Corrected',
        ]);
    }

    public function test_student_code_cannot_be_changed_by_editing(): void
    {
        $student = Student::where('student_code', 'BBT-R26-0009')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('editStudent', $student->id)
            ->set('fType', 'T')          // attempt to switch series
            ->set('fName', 'Still Maha')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $student->refresh();
        $this->assertSame('BBT-R26-0009', $student->student_code);
        $this->assertSame('R', $student->type);
    }

    public function test_enrol_deep_link_opens_the_wizard_at_step_two_with_the_student_chosen(): void
    {
        $student = Student::where('student_code', 'BBT-R26-0009')->firstOrFail();

        Livewire::actingAs($this->officer())
            ->withQueryParams(['enrol' => $student->id])
            ->test('pages.registrations')
            ->assertSet('wizardOpen', true)
            ->assertSet('step', 2)
            ->assertSet('mode', 'existing')
            ->assertSet('pickedStudentId', $student->id);
    }

    /**
     * The dashboard's quick actions are links, so the screens they point at have
     * to open the form themselves. Landing on a list and hunting for a button is
     * what the tiles exist to remove.
     */
    public function test_the_new_query_param_opens_a_blank_wizard(): void
    {
        Livewire::actingAs($this->officer())
            ->withQueryParams(['new' => 1])
            ->test('pages.registrations')
            ->assertSet('wizardOpen', true)
            ->assertSet('step', 1)
            ->assertSet('mode', 'new')
            ->assertSet('pickedStudentId', null);
    }

    public function test_the_new_query_param_opens_the_student_form(): void
    {
        Livewire::actingAs($this->officer())
            ->withQueryParams(['new' => 1])
            ->test('pages.students')
            ->assertSet('formOpen', true)
            ->assertSet('editingId', null);
    }

    /**
     * A stale bookmark should land on the list, not on a 403. The permission is
     * still enforced where it matters — `newStudent()` itself aborts — so this
     * only decides what an unprivileged GET does, and refusing to render a page
     * the user may otherwise read would be the wrong answer.
     */
    public function test_the_new_query_param_is_ignored_without_the_permission(): void
    {
        $officer = $this->officer();
        $officer->role->permissions()->where('permission_key', 'registrations.create')->delete();

        Livewire::actingAs($officer->refresh())
            ->withQueryParams(['new' => 1])
            ->test('pages.students')
            ->assertSet('formOpen', false)
            ->assertOk();
    }

    public function test_enrol_deep_link_respects_scope(): void
    {
        // A student that belongs only to another officer's enrolments.
        $student = Student::where('student_code', 'BBT-R26-0007')->firstOrFail(); // enrolled by admin

        Livewire::actingAs($this->officer())
            ->withQueryParams(['enrol' => $student->id])
            ->test('pages.registrations')
            ->assertSet('wizardOpen', false);
    }

    public function test_enrolling_an_existing_student_adds_an_admission_without_a_new_student(): void
    {
        $student = Student::where('student_code', 'BBT-R26-0009')->firstOrFail();
        $before = Student::count();
        $course = Course::where('code', 'GD-101')->firstOrFail();

        Livewire::actingAs($this->officer())
            ->withQueryParams(['enrol' => $student->id])
            ->test('pages.registrations')
            ->call('toggleCourse', $course->id)
            ->call('next')      // step 2 -> 3
            ->call('submit');

        $this->assertSame($before, Student::count(), 'No duplicate student record.');
        $this->assertDatabaseHas('admissions', [
            'student_id' => $student->id,
            'course_id' => $course->id,
        ]);
    }

    // ---- Live step-1 validation --------------------------------------------

    /**
     * A CNIC identifies a person, so one already on file means the student is
     * back for another course. The wizard offers them rather than letting the
     * officer fill in a whole duplicate and discover the clash at submit.
     */
    public function test_typing_an_existing_cnic_offers_that_student_instead_of_duplicating_them(): void
    {
        $existing = Student::where('student_code', 'BBT-R26-0009')->firstOrFail();

        $c = Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newCnic', $existing->cnic);

        $c->assertSet('cnicClashId', $existing->id);

        // Continue stays refused while the clash stands, even with every other
        // field filled in perfectly.
        $c->set('newName', 'Someone Else')
            ->set('newGuardian', 'Some Guardian')
            ->set('newPhone', '+92 300 1234567')
            ->call('next')
            ->assertSet('step', 1);

        // Taking the offer switches the wizard onto the existing record.
        $c->call('useExistingStudent')
            ->assertSet('mode', 'existing')
            ->assertSet('pickedStudentId', $existing->id)
            ->assertSet('cnicClashId', null)
            ->assertSet('newCnic', '');
    }

    /**
     * Wrongness is reported as soon as it is knowable; emptiness waits until the
     * officer has left the field.
     *
     * Every field starts empty, so a "required" error fired on keystroke accuses
     * someone of not having finished typing yet. The old code claimed in a
     * comment to be "silent while the number is still being typed" and then
     * errored on the first digit, which is how people learn to ignore red text.
     */
    public function test_step_one_reports_wrongness_at_once_but_emptiness_only_on_leaving(): void
    {
        $c = Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard');

        // Mid-entry. Not yet a wrong number, just an unfinished one.
        $c->set('newPhone', '12345');
        $this->assertArrayNotHasKey('phone', $c->get('wizErrors'));

        // Twelve CHARACTERS but only nine digits, because the mask spaces the
        // number out. The officer still has a digit to type, so this must stay
        // silent — a character-length threshold called it wrong here.
        $c->set('newPhone', '+92 300 1234');
        $this->assertArrayNotHasKey('phone', $c->get('wizErrors'),
            'A spaced, half-typed number is not a wrong one.');

        // Long enough to be a complete attempt, and still not a valid mobile.
        $c->set('newPhone', '1234512345123');
        $this->assertArrayHasKey('phone', $c->get('wizErrors'));

        $c->set('newPhone', '03001234567');
        $this->assertArrayNotHasKey('phone', $c->get('wizErrors'));

        // An untouched empty name says nothing...
        $this->assertArrayNotHasKey('name', $c->get('wizErrors'));

        // ...until the officer leaves it empty, which is a real answer.
        $c->call('touch', 'name');
        $this->assertArrayHasKey('name', $c->get('wizErrors'));

        // And it clears the moment there is something in it.
        $c->set('newName', 'A');
        $this->assertArrayNotHasKey('name', $c->get('wizErrors'));

        $c->set('newCnic', '35201-123');
        $this->assertArrayNotHasKey('cnic', $c->get('wizErrors'), 'Silent while still being typed.');

        $c->set('newCnic', '35201-1234567-9');
        $this->assertArrayNotHasKey('cnic', $c->get('wizErrors'));
        $c->assertSet('cnicClashId', null);
    }

    /**
     * The schema stopped requiring a guardian and a CNIC in 2026_08_08_000001 so
     * an imported roll could carry "name, course, batch, phone and money". A
     * counter that demands more than the database does is inventing its own rule.
     */
    public function test_a_name_and_a_phone_are_enough_to_register(): void
    {
        $course = Course::where('code', 'GD-101')->firstOrFail();

        Livewire::actingAs($this->officer())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newName', 'Guardianless Student')
            ->set('newPhone', '+92 300 7654321')
            ->call('next')
            ->assertSet('step', 2)
            ->call('toggleCourse', $course->id)
            ->call('next')
            ->call('submit');

        $student = Student::where('name', 'Guardianless Student')->firstOrFail();
        $this->assertNull($student->cnic);
        $this->assertNull($student->guardian_name);
    }

    /**
     * The two doors that create a student must agree about what a student is.
     *
     * The wizard was allowed to omit a guardian and a CNIC, and the Students
     * screen was not updated to match. Opening such a student for editing
     * assigned NULL to a `public string` property and threw a TypeError before
     * the form rendered, so the record could be created and then never touched
     * again — a 500 on the screen whose whole job is correcting a mistyped name.
     */
    public function test_a_student_registered_without_a_guardian_can_still_be_edited(): void
    {
        $course = Course::where('code', 'GD-101')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newName', 'Sparse Record')
            ->set('newPhone', '+92 300 4455661')
            ->call('next')
            ->call('toggleCourse', $course->id)
            ->call('next')
            ->call('submit');

        $student = Student::where('name', 'Sparse Record')->firstOrFail();
        $this->assertNull($student->guardian_name);
        $this->assertNull($student->cnic);

        Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('editStudent', $student->id)
            ->assertSet('formOpen', true)
            ->assertSet('fGuardian', '')
            ->assertSet('fCnic', '');
    }

    /** ...and saved again, without inventing the fields the wizard did not ask for. */
    public function test_a_student_without_a_guardian_can_be_saved_from_the_students_form(): void
    {
        $course = Course::where('code', 'GD-101')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newName', 'Sparse Record')
            ->set('newPhone', '+92 300 4455661')
            ->call('next')
            ->call('toggleCourse', $course->id)
            ->call('next')
            ->call('submit');

        $student = Student::where('name', 'Sparse Record')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('editStudent', $student->id)
            ->set('fName', 'Sparse Record Corrected')
            ->call('saveStudent')
            ->assertHasNoErrors();

        $student->refresh();
        $this->assertSame('Sparse Record Corrected', $student->name);
        // Still NULL, not '' — the UNIQUE cnic column treats '' as a real value.
        $this->assertNull($student->guardian_name);
        $this->assertNull($student->cnic);
    }

    /** Two guardianless students must not collide on the UNIQUE cnic column. */
    public function test_the_students_form_can_create_two_students_without_a_cnic(): void
    {
        foreach (['Blank One', 'Blank Two'] as $name) {
            Livewire::actingAs($this->admin())
                ->test('pages.students')
                ->call('newStudent')
                ->set('fName', $name)
                ->set('fPhone', '+92 300 9988771')
                ->call('saveStudent')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, Student::whereIn('name', ['Blank One', 'Blank Two'])->count());
        $this->assertSame(0, Student::whereIn('name', ['Blank One', 'Blank Two'])->whereNotNull('cnic')->count());
    }

    /** A half-typed CNIC is not "omitted", it is wrong, and must not be stored. */
    public function test_a_partial_cnic_is_refused_rather_than_stored(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newName', 'Partial Cnic')
            ->set('newPhone', '+92 300 1112223')
            ->set('newCnic', '35201-12')
            ->call('next')
            ->assertSet('step', 1);
    }

    /**
     * Blank must reach the column as NULL, never ''. The CNIC column is UNIQUE,
     * and both MySQL and SQLite exclude NULLs from uniqueness while treating ''
     * as an ordinary value, so storing the empty string would let the first
     * student without a CNIC save and collide with the second.
     */
    public function test_two_students_can_be_registered_without_a_cnic(): void
    {
        $course = Course::where('code', 'GD-101')->firstOrFail();

        foreach (['First NoCnic', 'Second NoCnic'] as $name) {
            Livewire::actingAs($this->admin())
                ->test('pages.registrations')
                ->call('openWizard')
                ->set('newName', $name)
                ->set('newPhone', '+92 301 1234567')
                ->call('next')
                ->call('toggleCourse', $course->id)
                ->call('next')
                ->call('submit');
        }

        $this->assertSame(2, Student::whereIn('name', ['First NoCnic', 'Second NoCnic'])->count());
        $this->assertSame(2, Student::whereIn('name', ['First NoCnic', 'Second NoCnic'])->whereNull('cnic')->count());
    }

    /** Siblings share a guardian, so the second child's form is mostly a retype. */
    public function test_a_guardian_already_on_file_is_offered_and_fills_the_phone(): void
    {
        $existing = Student::whereNotNull('guardian_name')->whereNotNull('phone')->firstOrFail();

        $c = Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newGuardian', mb_substr($existing->guardian_name, 0, 3));

        $names = array_column($c->viewData('guardianMatches') ?? [], 'name');
        $this->assertContains($existing->guardian_name, $names);

        $c->call('useGuardian', $existing->guardian_name, $existing->phone)
            ->assertSet('newGuardian', $existing->guardian_name)
            ->assertSet('newPhone', $existing->phone);
    }

    /** A suggestion may fill a blank, never overrule a number already typed. */
    public function test_a_guardian_suggestion_does_not_overwrite_a_typed_phone(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('newPhone', '+92 322 9998887')
            ->call('useGuardian', 'Some Guardian', '+92 300 0000000')
            ->assertSet('newPhone', '+92 322 9998887');
    }

    // ---- Drawer state -------------------------------------------------------

    /**
     * The drawer shell used to be gated on the open flag while its contents were
     * gated on the resolved record, so a student the viewer cannot see produced
     * an empty white panel with no header and therefore no close button.
     */
    public function test_the_drawer_never_opens_without_a_student_to_show(): void
    {
        $officer = $this->officer();

        // A student this officer did not enrol: outside their visibility scope.
        $unreachable = Student::whereNotIn('id', function ($q) use ($officer) {
            $q->select('student_id')->from('admissions')->where('enrolled_by', $officer->id);
        })->firstOrFail();

        Livewire::actingAs($officer)
            ->test('pages.students')
            ->call('viewStudent', $unreachable->id)
            ->assertSet('drawerOpen', false)
            ->assertSet('selectedId', null);
    }

    public function test_the_drawer_opens_for_a_visible_student_and_closes_cleanly(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.students')
            ->call('viewStudent', Student::where('student_code', 'BBT-R26-0009')->firstOrFail()->id)
            ->assertSet('drawerOpen', true)
            ->call('closeDrawer')
            ->assertSet('drawerOpen', false)
            ->assertSet('selectedId', null);
    }
}
