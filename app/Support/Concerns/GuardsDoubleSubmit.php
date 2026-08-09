<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * The component half of GAP-08: carry one token per opened form.
 *
 * `$opKeys` is a public Livewire property, so it rides the component snapshot
 * to the browser and back. That is what makes the guard work — two clicks
 * landing before the first response returns both send the snapshot as it was
 * when the form opened, so both carry the SAME token and the second is
 * recognised as a repeat.
 *
 * Keyed by form rather than one token per component, because a page can have
 * two of them open at once: Registrations carries both the payment dialog and
 * the enrolment wizard, and a single token would mean opening one silently
 * disarmed the other.
 *
 * Tokens are re-minted after every completed operation as well as on open.
 * Without that, an officer taking a genuine second payment from the same drawer
 * would have it swallowed as a duplicate — a worse bug than the one being
 * fixed, because it loses money that was actually handed over. Every opener and
 * every success path re-mints, and the tests assert that a real second payment
 * still lands.
 */
trait GuardsDoubleSubmit
{
    /** @var array<string, string> form name => token */
    public array $opKeys = [];

    public function freshOperationKey(string $form): void
    {
        $this->opKeys[$form] = (string) Str::uuid();
    }

    /**
     * Empty when the form was never opened. `Operations::once()` refuses that
     * rather than waving it through, so a mis-wired screen fails loudly instead
     * of quietly losing its protection.
     *
     * Coerced and truncated because `$opKeys` is public: the client can put an
     * array or a megabyte of text in it, and neither a TypeError on the way in
     * nor an unbounded row on the way out is an acceptable answer to that. 64
     * matches the column, and the value is hashed before storage anyway.
     */
    public function operationKey(string $form): string
    {
        $key = $this->opKeys[$form] ?? '';

        return is_string($key) ? mb_substr($key, 0, 64) : '';
    }
}
