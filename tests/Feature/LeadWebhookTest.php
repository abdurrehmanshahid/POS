<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function send(array $data, ?string $token = 'secret')
    {
        return $this->postJson('/webhooks/leads', $data, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    public function test_it_is_closed_when_no_secret_is_configured(): void
    {
        config(['services.leads.webhook_token' => null]);

        $this->send(['name' => 'Ayesha'])->assertNotFound();
        $this->assertSame(0, Lead::count());
    }

    public function test_it_refuses_a_wrong_or_missing_token(): void
    {
        config(['services.leads.webhook_token' => 'secret']);

        $this->send(['name' => 'Ayesha'], 'wrong')->assertUnauthorized();
        $this->send(['name' => 'Ayesha'], null)->assertUnauthorized();
        $this->assertSame(0, Lead::count());
    }

    public function test_a_lead_lands_in_unread(): void
    {
        config(['services.leads.webhook_token' => 'secret']);

        $this->send(['full_name' => 'Ayesha Khan', 'phone_number' => '0300', 'email' => 'a@x.pk', 'id' => 'L1'])
            ->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertSame('unread', $lead->stage);
        $this->assertSame('Ayesha Khan', $lead->name);
        $this->assertSame('meta', $lead->source);
    }

    public function test_a_retried_delivery_does_not_duplicate_or_reset_the_lead(): void
    {
        config(['services.leads.webhook_token' => 'secret']);

        $this->send(['name' => 'Ayesha', 'id' => 'L1'])->assertCreated();
        Lead::firstOrFail()->update(['stage' => 'contacted']);

        $this->send(['name' => 'Ayesha', 'id' => 'L1'])->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame('contacted', Lead::first()->stage);
    }

    public function test_a_lead_without_a_name_is_rejected(): void
    {
        config(['services.leads.webhook_token' => 'secret']);

        $this->send(['phone' => '0300'])->assertStatus(422);
    }
}
