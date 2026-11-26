<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch new leads from the Meta lead forms of the institute's page and put
 * them in Unread.
 *
 * This asks Meta, on a schedule, rather than waiting to be told by a webhook.
 * A pull needs nothing from Meta but a token: no public callback to verify, no
 * app that has to be switched to Live, and a run that fails loses nothing,
 * because the next one asks for the same window again.
 *
 * Each run asks for leads created since the last successful run, less an
 * overlap. The overlap, and a lost cursor, are both safe: a lead is keyed by
 * its Meta id, so one seen twice is skipped, and one somebody deleted stays
 * deleted.
 *
 * The token travels in the Authorization header and pages are followed by
 * cursor, never by Meta's ready-made `next` URL, so it cannot end up in a log
 * line or an exception message.
 */
class PullMetaLeads extends Command
{
    protected $signature = 'leads:pull-meta
        {--days= : Look this many days back instead of resuming from the last run. Use once to bring in older leads.}';

    protected $description = 'Fetch new leads from the Meta lead forms into Unread';

    private const CURSOR_KEY = 'leads.meta.pulled_until';

    /** Seconds re-read on every run, for leads Meta lists a little late. */
    private const OVERLAP = 600;

    public function handle(): int
    {
        $pageId = (string) config('services.meta.page_id');
        $token = (string) config('services.meta.token');

        if ($pageId === '' || $token === '') {
            $this->error('META_PAGE_ID and META_LEADS_TOKEN must both be set in .env.');

            return self::FAILURE;
        }

        $startedAt = now()->getTimestamp();
        $since = $this->option('days') !== null
            ? $startedAt - max(1, (int) $this->option('days')) * 86400
            : (Cache::get(self::CURSOR_KEY) ?? $startedAt - 86400) - self::OVERLAP;

        try {
            // Forms and their leads are read as the page. A system-user token is
            // exchanged for the page's own; a page token simply returns itself.
            $pageToken = $this->graph($token)->get($pageId, ['fields' => 'access_token'])
                ->throw()->json('access_token') ?: $token;
            $graph = $this->graph($pageToken);

            $created = 0;
            foreach ($this->all($graph, "$pageId/leadgen_forms", ['fields' => 'id,name,status']) as $form) {
                if (($form['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                    continue;
                }

                $leads = $this->all($graph, $form['id'].'/leads', [
                    'fields' => 'id,created_time,field_data,ad_name,campaign_name',
                    'filtering' => json_encode([
                        ['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since],
                    ]),
                ]);

                foreach ($leads as $lead) {
                    $created += (int) $this->store($lead, $form['name'] ?? null);
                }
            }
        } catch (Throwable $e) {
            $this->error('Meta lead pull failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Cache::forever(self::CURSOR_KEY, $startedAt);
        $this->info("Meta leads: {$created} new.");

        return self::SUCCESS;
    }

    private function graph(string $token): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.config('services.meta.graph_version'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    /** Every row of a Graph list, following its cursor to the last page. */
    private function all(PendingRequest $graph, string $path, array $query): array
    {
        $rows = [];
        $after = null;

        do {
            $page = $graph->get($path, $query + ['limit' => 100] + ($after ? ['after' => $after] : []))
                ->throw()->json();

            $rows = [...$rows, ...($page['data'] ?? [])];
            $after = isset($page['paging']['next']) ? ($page['paging']['cursors']['after'] ?? null) : null;
        } while ($after);

        return $rows;
    }

    /** Returns whether the lead was new. */
    private function store(array $data, ?string $formName): bool
    {
        $lead = Lead::withTrashed()->firstOrNew(['external_id' => mb_substr((string) $data['id'], 0, 80)]);
        if ($lead->exists) {
            return false;
        }

        // The form's answers, keyed by question: full_name, phone_number, email,
        // and whatever custom questions the form asks.
        $answers = [];
        foreach ($data['field_data'] ?? [] as $field) {
            $value = trim(implode(', ', $field['values'] ?? []));
            if ($value !== '') {
                $answers[Str::snake(strtolower(trim($field['name'] ?? '')))] = $value;
            }
        }

        $take = function (array $keys) use (&$answers): ?string {
            foreach ($keys as $key) {
                if (isset($answers[$key])) {
                    $value = $answers[$key];
                    unset($answers[$key]);

                    return $value;
                }
            }

            return null;
        };

        $phone = $take(['phone_number', 'phone', 'mobile', 'whatsapp_number']);
        $name = $take(['full_name', 'name'])
            ?? trim($take(['first_name']).' '.$take(['last_name']));
        $email = $take(['email', 'work_email']);

        // Whatever is left is an answer to a custom question, and worth keeping.
        $notes = collect($answers)
            ->map(fn ($value, $question) => Str::headline($question).': '.$value)
            ->implode("\n");

        $lead->fill([
            'name' => mb_substr($name !== '' ? $name : ($phone ?? 'Unnamed lead'), 0, 120),
            'phone' => $phone === null ? null : mb_substr($phone, 0, 40),
            'email' => $email === null ? null : mb_substr($email, 0, 120),
            'course_interest' => mb_substr((string) ($data['campaign_name'] ?? $data['ad_name'] ?? $formName), 0, 120) ?: null,
            'notes' => $notes !== '' ? $notes : null,
            'source' => 'meta',
            'stage' => 'unread',
            'stage_changed_at' => now(),
        ]);

        // When the person filled the form in, so older leads brought in with
        // --days sort where they belong rather than all at this minute.
        if (isset($data['created_time'])) {
            $lead->created_at = Carbon::parse($data['created_time']);
        }

        $lead->save();

        return true;
    }
}
