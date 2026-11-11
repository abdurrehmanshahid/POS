<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PullMetaLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.page_id' => 'PAGE', 'services.meta.token' => 'system-token']);
    }

    private function fakeMeta(array $leadPages): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/PAGE/leadgen_forms*' => Http::response(['data' => [
                ['id' => 'FORM1', 'name' => 'Web course form', 'status' => 'ACTIVE'],
                ['id' => 'OLD', 'name' => 'Retired form', 'status' => 'ARCHIVED'],
            ]]),
            'graph.facebook.com/*/PAGE?*' => Http::response(['access_token' => 'page-token', 'id' => 'PAGE']),
            'graph.facebook.com/*/FORM1/leads*' => Http::sequence($leadPages),
        ]);
    }

    private function lead(string $id, array $fields, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'created_time' => '2026-11-10T09:30:00+0000',
            'field_data' => collect($fields)->map(fn ($v, $k) => ['name' => $k, 'values' => [$v]])->values()->all(),
        ];
    }

    public function test_it_is_refused_without_a_page_and_token(): void
    {
        config(['services.meta.token' => null]);
        Http::preventStrayRequests();

        $this->artisan('leads:pull-meta')->assertFailed();
    }

    public function test_a_meta_lead_lands_in_unread(): void
    {
        $this->fakeMeta([Http::response(['data' => [
            $this->lead('111', [
                'full_name' => 'Ayesha Khan', 'phone_number' => '+923001234567',
                'email' => 'a@x.pk', 'which_city?' => 'Lahore',
            ], ['campaign_name' => 'Web Dev Nov']),
        ]])]);

        $this->artisan('leads:pull-meta')->assertSuccessful();

        $lead = Lead::firstOrFail();
        $this->assertSame('unread', $lead->stage);
        $this->assertSame('Ayesha Khan', $lead->name);
        $this->assertSame('+923001234567', $lead->phone);
        $this->assertSame('a@x.pk', $lead->email);
        $this->assertSame('Web Dev Nov', $lead->course_interest);
        $this->assertSame('meta', $lead->source);
        $this->assertSame('111', $lead->external_id);
        $this->assertSame('Which City?: Lahore', $lead->notes);
        $this->assertSame('2026-11-10 09:30:00', $lead->created_at->toDateTimeString());

        // Leads are read as the page, and only from the form still running.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'FORM1/leads') && $r->hasHeader('Authorization', 'Bearer page-token'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'OLD/leads'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'access_token='));
    }

    public function test_every_page_of_leads_is_read(): void
    {
        $this->fakeMeta([
            Http::response([
                'data' => [$this->lead('1', ['first_name' => 'Ali', 'last_name' => 'Raza'])],
                'paging' => ['cursors' => ['after' => 'CUR'], 'next' => 'https://graph.facebook.com/next'],
            ]),
            Http::response(['data' => [$this->lead('2', ['phone_number' => '0300'])]]),
        ]);

        $this->artisan('leads:pull-meta')->assertSuccessful();

        $this->assertSame(['Ali Raza', '0300'], Lead::orderBy('external_id')->pluck('name')->all());
        Http::assertSent(fn (Request $r) => ($r->data()['after'] ?? null) === 'CUR');
    }

    public function test_a_lead_seen_again_is_neither_duplicated_nor_reset_nor_restored(): void
    {
        $page = Http::response(['data' => [
            $this->lead('1', ['full_name' => 'Ayesha']),
            $this->lead('2', ['full_name' => 'Bilal']),
        ]]);
        $this->fakeMeta([$page, $page]);

        $this->artisan('leads:pull-meta')->assertSuccessful();
        Lead::where('external_id', '1')->update(['stage' => 'contacted']);
        Lead::where('external_id', '2')->delete();

        $this->artisan('leads:pull-meta')->assertSuccessful();

        $this->assertSame(2, Lead::withTrashed()->count());
        $this->assertSame('contacted', Lead::where('external_id', '1')->value('stage'));
        $this->assertTrue(Lead::withTrashed()->where('external_id', '2')->firstOrFail()->trashed());
    }

    public function test_a_failed_run_fails_without_leaking_the_token(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

        $this->artisan('leads:pull-meta')
            ->doesntExpectOutputToContain('system-token')
            ->assertFailed();

        $this->assertSame(0, Lead::count());
    }
}
