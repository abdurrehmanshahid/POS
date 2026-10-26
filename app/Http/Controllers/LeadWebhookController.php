<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where an automation (Zapier, Make, Pabbly) drops a lead that came in from a
 * Meta lead ad.
 *
 * It sits outside the `web` group on purpose: there is no session or CSRF token
 * on a machine-to-machine call, and the shared secret is what authenticates it.
 * With no secret configured the endpoint does not exist, so a deploy that
 * forgets to set one is closed, not open.
 */
class LeadWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.leads.webhook_token');
        abort_if($secret === '', 404);

        $given = (string) ($request->bearerToken() ?? $request->header('X-Webhook-Token'));
        abort_unless(hash_equals($secret, $given), 401);

        $name = $this->first($request, ['name', 'full_name', 'client_name'])
            ?? trim($this->first($request, ['first_name']).' '.$this->first($request, ['last_name']));

        if ($name === '') {
            return response()->json(['error' => 'name is required'], 422);
        }

        $externalId = $this->first($request, ['id', 'lead_id', 'external_id']);

        $attributes = [
            'name' => mb_substr($name, 0, 120),
            'phone' => $this->limit($this->first($request, ['phone', 'phone_number', 'mobile']), 40),
            'email' => $this->limit($this->first($request, ['email']), 120),
            'course_interest' => $this->limit($this->first($request, ['course', 'course_interest', 'campaign', 'ad_name']), 120),
            'notes' => $this->first($request, ['notes', 'message']),
            'source' => $this->limit($this->first($request, ['source']) ?? 'meta', 40),
        ];

        // A webhook retried by the sender must not make the lead twice, and must
        // not drag a lead someone already moved back to Unread.
        if ($externalId !== null) {
            $lead = Lead::withTrashed()->firstOrNew(['external_id' => mb_substr($externalId, 0, 80)]);
        } else {
            $lead = new Lead;
        }

        $created = ! $lead->exists;
        if ($created) {
            $lead->fill($attributes + ['stage' => 'unread', 'stage_changed_at' => now()])->save();
        }

        return response()->json(['id' => $lead->id, 'created' => $created], $created ? 201 : 200);
    }

    /** The first of these keys the sender actually filled in. */
    private function first(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function limit(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
