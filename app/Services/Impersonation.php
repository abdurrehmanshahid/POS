<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "View the portal as this user."
 *
 * Debugging a permission complaint by reasoning about a role matrix is slow and
 * error-prone; seeing the officer's actual screen takes a second. The risk is
 * obvious, so the design constrains it in four ways:
 *
 *  1. **Separate guards make it safe to hold both identities.** The super admin
 *     keeps their `superadmin` session while a `web` session is opened for the
 *     target. Stopping is a guard logout, not a re-authentication, so there is
 *     no window in which the operator is neither.
 *
 *  2. **The session records who is really driving.** `impersonator_id` is set,
 *     the layout shows a permanent banner, and it cannot be cleared from the
 *     client. Livewire/Blade only ever read it.
 *
 *  3. **Attribution is never laundered.** Everything the impersonator does is
 *     signed with the target's name in the domain data (an admission genuinely
 *     is enrolled by that officer) but the audit trail records the super admin
 *     as the real actor. Both facts are true and both are kept.
 *
 *  4. **It is not a privilege ladder.** You may only impersonate an active,
 *     non-removed staff account, never another super admin, because there is
 *     nothing to gain and a great deal to hide behind.
 */
class Impersonation
{
    private const KEY = 'impersonator_id';

    private const NAME_KEY = 'impersonator_name';

    /** @throws RuntimeException */
    public function start(SuperAdmin $actor, User $target): void
    {
        if (! $target->is_active || $target->trashed()) {
            throw new RuntimeException('That account is not active, so it cannot be viewed.');
        }

        if ($this->isImpersonating()) {
            throw new RuntimeException('Already viewing as another user, stop that session first.');
        }

        // Audited BEFORE the switch, while the actor is unambiguously themselves.
        Audit::record('Impersonation started', $actor, [
            'subject' => $target,
            'subject_label' => $target->username.' · '.$target->name,
            'new_value' => $target->username,
        ]);

        Auth::guard('web')->login($target);

        session()->put(self::KEY, $actor->getKey());
        session()->put(self::NAME_KEY, $actor->name);
    }

    public function stop(): void
    {
        if (! $this->isImpersonating()) {
            return;
        }

        $target = Auth::guard('web')->user();
        $actor = SuperAdmin::find(session(self::KEY));

        Auth::guard('web')->logout();
        session()->forget([self::KEY, self::NAME_KEY]);

        if ($actor) {
            Audit::record('Impersonation ended', $actor, [
                'subject' => $target,
                'subject_label' => $target?->username.' · '.$target?->name,
            ]);
        }
    }

    public function isImpersonating(): bool
    {
        return session()->has(self::KEY);
    }

    /**
     * The super admin really behind the current `web` session, if any.
     *
     * Used by {@see Audit} so that actions performed while impersonating are
     * attributed to the human who performed them, not the identity they wore.
     */
    public function impersonator(): ?SuperAdmin
    {
        return $this->isImpersonating() ? SuperAdmin::find(session(self::KEY)) : null;
    }

    public function impersonatorName(): ?string
    {
        return session(self::NAME_KEY);
    }
}
