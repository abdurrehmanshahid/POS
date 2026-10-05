<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Archiving a course moves it off the Active and Inactive tabs and out of
 * everything that offers courses, without losing it.
 */
class CourseArchiveTest extends TestCase
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

    private function course(): Course
    {
        return Course::where('code', 'GD-101')->firstOrFail();
    }

    public function test_archiving_hides_the_course_and_shows_it_under_archived(): void
    {
        $course = $this->course();

        $page = Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('setArchived', $course->id, true);

        $course->refresh();
        $this->assertTrue($course->isArchived());
        $this->assertFalse($course->is_active, 'Archived courses can no longer be registered for.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Course archived', 'subject_id' => $course->id]);

        $page->assertViewHas('courses', fn ($courses) => ! $courses->contains('id', $course->id));
        $page->set('tab', 'inactive')
            ->assertViewHas('courses', fn ($courses) => ! $courses->contains('id', $course->id));
        $page->set('tab', 'archived')
            ->assertViewHas('courses', fn ($courses) => $courses->contains('id', $course->id));
    }

    public function test_restoring_brings_the_course_back_as_inactive(): void
    {
        $course = $this->course();

        Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('setArchived', $course->id, true)
            ->call('setArchived', $course->id, false)
            ->set('tab', 'inactive')
            ->assertViewHas('courses', fn ($courses) => $courses->contains('id', $course->id));

        $course->refresh();
        $this->assertFalse($course->isArchived());
        $this->assertFalse($course->is_active);
    }

    public function test_officers_without_course_permission_cannot_archive(): void
    {
        $course = $this->course();
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $officer->role->permissions()->where('permission_key', 'courses.manage')->delete();

        Livewire::actingAs($officer->refresh())->test('pages.courses')
            ->call('setArchived', $course->id, true);

        $this->assertFalse($course->refresh()->isArchived());
    }
}
