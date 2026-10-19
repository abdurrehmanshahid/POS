<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\ChallanEdits;
use App\Services\Installments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The super admin can correct what a challan bills; nobody else can, and no
 * correction leaves the challan disagreeing with its money or its schedule.
 */
class ChallanEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function su(): SuperAdmin
    {
        $su = SuperAdmin::firstOrFail();
        $su->forceFill(['password' => 'Owner@Pass1'])->save();

        return $su;
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    /** Unpaid, single course, no collections. */
    private function challan(): Challan
    {
        return Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
    }

    private function edit(Challan $c, int $base, int $discount = 0, int $certificate = 0): Challan
    {
        return app(ChallanEdits::class)->edit($c, $this->su(), [
            'base_amount' => $base, 'discount_amount' => $discount, 'certificate_amount' => $certificate,
        ], 'Billed at the wrong fee');
    }

    public function test_the_super_admin_edits_a_challan_from_the_panel(): void
    {
        $c = $this->challan();

        Livewire::actingAs($this->su(), 'superadmin')
            ->test('superadmin.challans')
            ->call('askEdit', $c->id)
            ->set('editBase', '30000')
            ->set('editDiscount', '2500')
            ->set('editCertificate', '700')
            ->set('editReason', 'Fee was raised before enrolment')
            ->set('editSecret', 'Owner@Pass1')
            ->call('saveEdit')
            ->assertSet('editId', null);

        $c->refresh();
        $this->assertSame(30000, $c->base_amount);
        $this->assertSame(2500, $c->discount_amount);
        $this->assertSame(28200, $c->net_amount);
        $this->assertSame('Fee was raised before enrolment', $c->discount_reason);
        // The course shares still reconstruct the base.
        $this->assertSame(30000, $c->liveBilledTotal());

        $log = AuditLog::where('action', 'Challan amount edited')->where('challan_id', $c->id)->firstOrFail();
        $this->assertSame('superadmin', $log->actor_type);
        $this->assertSame('Fee was raised before enrolment', $log->context['reason']);
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        $c = $this->challan();
        $before = $c->net_amount;

        Livewire::actingAs($this->su(), 'superadmin')
            ->test('superadmin.challans')
            ->call('askEdit', $c->id)
            ->set('editBase', '1000')
            ->set('editReason', 'Testing')
            ->set('editSecret', 'not-it')
            ->call('saveEdit')
            ->assertSet('editError', 'That password is not correct.');

        $this->assertSame($before, $c->fresh()->net_amount);
    }

    public function test_staff_cannot_reach_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('superadmin.challans'))
            ->assertRedirect();
    }

    public function test_net_cannot_go_below_what_was_collected(): void
    {
        $c = $this->challan();
        app(ChallanActions::class)->recordPayment($c, $this->admin(), 10000, 'Cash');

        try {
            $this->edit($c, 8000);
            $this->fail('Net below the collected amount should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already collected', $e->getMessage());
        }

        // Down to exactly what was collected settles it.
        $edited = $this->edit($c, 10000);
        $this->assertSame('paid', $edited->status);
        $this->assertSame(0, $edited->balance());

        // Raised again, it is owed again.
        $edited = $this->edit($c, 15000);
        $this->assertSame('unpaid', $edited->status);
        $this->assertNull($edited->paid_at);
        $this->assertSame(5000, $edited->balance());
    }

    public function test_the_discount_cannot_exceed_the_fee(): void
    {
        $this->expectException(RuntimeException::class);
        $this->edit($this->challan(), 5000, 6000);
    }

    public function test_an_installment_plan_follows_the_new_net(): void
    {
        $c = $this->challan();
        $this->edit($c, 25000);
        app(Installments::class)->schedule($c->refresh(), [
            ['amount' => 12500, 'due_date' => '2026-08-01'],
            ['amount' => 12500, 'due_date' => '2026-09-01'],
        ]);

        // A cut comes off the last part.
        $this->edit($c, 20000);
        $this->assertSame([12500, 7500], $c->installments()->orderBy('seq')->pluck('amount')->all());

        // A rise goes on the last part.
        $this->edit($c, 26000);
        $this->assertSame([12500, 13500], $c->installments()->orderBy('seq')->pluck('amount')->all());

        // A cut that empties the last part leaves one, and the plan is over.
        $edited = $this->edit($c, 10000);
        $this->assertSame([10000], $c->installments()->orderBy('seq')->pluck('amount')->all());
        $this->assertSame('full', $edited->plan);
    }
}
