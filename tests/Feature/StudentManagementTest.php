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
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
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

    public function test_step_one_fields_validate_as_they_are_typed(): void
    {
        $c = Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard');

        // Still on step 1, nothing submitted, yet the field already objects.
        $c->set('newPhone', '12345');
        $this->assertArrayHasKey('phone', $c->get('wizErrors'));

        $c->set('newPhone', '03001234567');
        $this->assertArrayNotHasKey('phone', $c->get('wizErrors'));

        $c->set('newCnic', '35201-123');
        $this->assertArrayNotHasKey('cnic', $c->get('wizErrors'), 'Silent while still being typed.');

        $c->set('newCnic', '35201-1234567-9');
        $this->assertArrayNotHasKey('cnic', $c->get('wizErrors'));
        $c->assertSet('cnicClashId', null);
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
