<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create(['role_id' => 'admin']);
    }

    public function test_a_new_lead_lands_in_unread_and_can_be_moved_along(): void
    {
        $user = $this->admin();

        Volt::actingAs($user)->test('pages.leads')
            ->set('fName', 'Ayesha')->call('save')
            ->assertSee('Ayesha');

        $lead = Lead::firstOrFail();
        $this->assertSame('unread', $lead->stage);

        Volt::actingAs($user)->test('pages.leads')
            ->call('move', $lead->id, 'demo')
            ->call('setTab', 'demo')
            ->assertSee('Ayesha');

        $this->assertSame('demo', $lead->fresh()->stage);
    }

    public function test_the_page_is_closed_without_the_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Role::create(['id' => 'nobody', 'name' => 'Nobody', 'is_system' => false, 'tone' => 'navy']);
        $user = User::factory()->create(['role_id' => 'nobody']);

        $this->actingAs($user)->get('/leads')->assertForbidden();
    }
}
