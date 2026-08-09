<?php

use App\Models\User;
use App\Services\Audit;
use App\Services\Impersonation;
use App\Services\RecordRemoval;
use App\Support\Concerns\ConfirmsDangerously;
use App\Support\Format;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Staff administration from outside the institute.
 *
 * Everything here bypasses the PrivilegeGuard that constrains admins on their
 * own Staff screen, deliberately. This is the break-glass identity whose whole
 * job is to repair an institute that has locked itself out (a last admin
 * deactivated, an officer who lost their phone). What replaces those guards is
 * the requirement that every action passes a fresh second factor and lands in
 * the append-only audit trail.
 */
new #[Layout('components.layouts.super')] class extends Component {
    use ConfirmsDangerously;

    public string $q = '';

    public bool $showRemoved = false;

    /** One-time display of a generated temporary password. */
    public string $issuedPassword = '';

    public string $issuedFor = '';

    /** The active state the open toggle dialog was opened against. */
    public bool $toggleFrom = false;

    private function actor(): User|\App\Models\SuperAdmin
    {
        return auth()->guard('superadmin')->user();
    }

    private function target(): ?User
    {
        return $this->dangerId ? User::withTrashed()->find($this->dangerId) : null;
    }

    public function with(): array
    {
        $users = User::withTrashed()
            ->with('role')
            ->when(! $this->showRemoved, fn ($q) => $q->whereNull('deleted_at'))
            ->when($this->q !== '', function ($q) {
                $term = '%'.Str::lower(trim($this->q)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->orderBy('name')
            ->get();

        return [
            'users' => $users,
            'removal' => app(RecordRemoval::class),
        ];
    }

    // ---- Action launchers ---------------------------------------------------

    public function askResetPassword(int $id): void
    {
        $u = User::withTrashed()->findOrFail($id);
        $this->askDanger([
            'kind' => 'reset-password',
            'id' => $u->id,
            'title' => 'Reset password for '.$u->name,
            'body' => 'A new temporary password is generated and shown once. '.$u->name
                .' must change it at their next sign-in, and every "keep me signed in" cookie on their other devices is invalidated.',
            'confirmLabel' => 'Issue temporary password',
        ]);
    }

    public function askResetTwoFactor(int $id): void
    {
        $u = User::withTrashed()->findOrFail($id);
        $this->askDanger([
            'kind' => 'reset-2fa',
            'id' => $u->id,
            'title' => 'Reset two-factor for '.$u->name,
            'body' => 'Clears their authenticator secret and all recovery codes. If their role requires a second factor they will be forced to enrol a new one at next sign-in. Use this when a phone is lost.',
            'confirmLabel' => 'Reset two-factor',
        ]);
    }

    public function askToggleActive(int $id): void
    {
        $u = User::withTrashed()->findOrFail($id);
        $on = ! $u->is_active;

        // The state we are acting against, so the confirm step can tell whether
        // the row moved underneath it.
        //
        // `doToggleActive` flips whatever it finds, so two confirmations
        // landing together flipped twice and left the account exactly as it
        // started, while the toast and the audit row both said it had changed.
        // Step-up hides that for anyone on TOTP because the accepted timestep
        // is burned; an actor falling back to password re-entry got the bug.
        //
        // Recording the state we came FROM rather than the state we want keeps
        // the audit row's old_value derived from the row instead of asserted by
        // the client, and makes tampering with this property a refusal rather
        // than a way to steer the outcome.
        $this->toggleFrom = (bool) $u->is_active;

        $this->askDanger([
            'kind' => 'toggle-active',
            'id' => $u->id,
            'title' => ($on ? 'Reactivate ' : 'Deactivate ').$u->name,
            'body' => $on
                ? $u->name.' will be able to sign in again with their existing password.'
                : $u->name.' is signed out immediately and cannot sign in. Their admissions and audit trail are untouched.',
            'confirmLabel' => $on ? 'Reactivate' : 'Deactivate',
        ]);
    }

    public function askRemove(int $id): void
    {
        $u = User::findOrFail($id);
        $this->askDanger([
            'kind' => 'remove',
            'id' => $u->id,
            'title' => 'Remove '.$u->name,
            'body' => 'The account is hidden everywhere and signed out immediately. Admissions they signed keep their name, and the record can be restored later. This is reversible.',
            'phrase' => $u->username,
            'confirmLabel' => 'Remove account',
            'needsReason' => true,
        ]);
    }

    public function askRestore(int $id): void
    {
        $u = User::withTrashed()->findOrFail($id);
        $this->askDanger([
            'kind' => 'restore',
            'id' => $u->id,
            'title' => 'Restore '.$u->name,
            'body' => 'The record comes back, but sign-in stays disabled until you reactivate it explicitly. Recovering data and restoring access are separate decisions.',
            'confirmLabel' => 'Restore record',
        ]);
    }

    public function askPurge(int $id): void
    {
        $u = User::withTrashed()->findOrFail($id);
        $removal = app(RecordRemoval::class);

        if ($blocker = $removal->purgeBlocker($u)) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'Cannot purge', msg: $blocker);

            return;
        }

        $this->askDanger([
            'kind' => 'purge',
            'id' => $u->id,
            'title' => 'Permanently destroy '.$u->name,
            'body' => 'This deletes the account row outright. It cannot be undone and there is no backup unless you have taken one. A snapshot is written to the audit log first.',
            'phrase' => $u->username,
            'irreversible' => true,
            'confirmLabel' => 'Purge permanently',
            'needsReason' => true,
        ]);
    }

    public function impersonate(int $id): void
    {
        $u = User::findOrFail($id);

        try {
            app(Impersonation::class)->start($this->actor(), $u);
        } catch (\RuntimeException $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Cannot view as', msg: $e->getMessage());

            return;
        }

        $this->redirect(route('dashboard'), navigate: false);
    }

    // ---- The one confirmed entry point --------------------------------------

    public function confirmDanger(): void
    {
        if (! $this->dangerCleared($this->actor())) {
            return;
        }

        $u = $this->target();
        if (! $u) {
            $this->dangerError = 'That account no longer exists.';

            return;
        }

        $removal = app(RecordRemoval::class);
        $reason = trim($this->dangerReason);
        $kind = $this->dangerKind;

        match ($kind) {
            'reset-password' => $this->doResetPassword($u),
            'reset-2fa' => $this->doResetTwoFactor($u),
            'toggle-active' => $this->doToggleActive($u),
            'remove' => $removal->remove($u, $this->actor(), $reason),
            'restore' => $removal->restore($u, $this->actor()),
            'purge' => $removal->purge($u, $this->actor(), $reason),
            default => null,
        };

        $this->closeDanger();

        if ($kind !== 'reset-password') {
            $this->dispatch('bbt-toast',
                tone: $kind === 'purge' ? 'err' : 'ok',
                title: match ($kind) {
                    'reset-2fa' => 'Two-factor reset',
                    'toggle-active' => $u->fresh()?->is_active ? 'Account reactivated' : 'Account deactivated',
                    'remove' => 'Account removed',
                    'restore' => 'Account restored',
                    'purge' => 'Account destroyed',
                    default => 'Done',
                },
                msg: $u->name,
            );
        }
    }

    private function doResetPassword(User $u): void
    {
        // Generated, never chosen by the operator, an admin who picks the
        // temporary password knows a working credential for someone else's
        // account until they change it.
        $temp = Str::password(14, symbols: false);

        $u->forceFill([
            'password' => $temp,              // hashed by the model cast
            'must_reset_password' => true,
            // Kills every "keep me signed in" cookie. If the reset is happening
            // because the account is compromised, leaving those alive defeats it.
            'remember_token' => Str::random(60),
        ])->save();

        Audit::record('Password reset by super admin', $this->actor(), [
            'subject' => $u,
            'subject_label' => $u->username.' · '.$u->name,
            'field' => 'password',
            'context' => ['reason' => $this->dangerReason ?: null, 'sessions_invalidated' => true],
        ]);

        $this->issuedPassword = $temp;
        $this->issuedFor = $u->name;
    }

    private function doResetTwoFactor(User $u): void
    {
        $u->clearTwoFactor();

        Audit::record('Two-factor reset by super admin', $this->actor(), [
            'subject' => $u,
            'subject_label' => $u->username.' · '.$u->name,
            'field' => 'two_factor_secret',
            'old_value' => 'enrolled',
            'new_value' => 'cleared',
        ]);
    }

    private function doToggleActive(User $u): void
    {
        // The row moved since the dialog was opened — a second confirmation of
        // the same dialog, or another superadmin who got there first. Flipping
        // again would undo their work and append an audit row for a transition
        // that never happened.
        if ((bool) $u->is_active !== $this->toggleFrom) {
            return;
        }

        $on = ! $u->is_active;

        $u->forceFill([
            'is_active' => $on,
            'deactivated_at' => $on ? null : now(),
            'remember_token' => $on ? $u->remember_token : null,
        ])->save();

        Audit::record($on ? 'Account reactivated' : 'Account deactivated', $this->actor(), [
            'subject' => $u,
            'subject_label' => $u->username.' · '.$u->name,
            'field' => 'is_active',
            'old_value' => $on ? 'inactive' : 'active',
            'new_value' => $on ? 'active' : 'inactive',
        ]);
    }

    public function dismissIssued(): void
    {
        $this->reset('issuedPassword', 'issuedFor');
    }
}; ?>

<div class="container-app anim-fade">

    {{-- One-time temporary password ------------------------------------- --}}
    @if ($issuedPassword)
        <div class="card" style="padding:20px 22px;margin-bottom:20px;border:1.5px solid var(--due);background:var(--due-bg)">
            <div style="display:flex;align-items:flex-start;gap:13px">
                <x-icon name="key" :size="20" style="color:var(--due);flex:none;margin-top:2px" />
                <div style="flex:1;min-width:0">
                    <div style="font-size:var(--fs-base);font-weight:800;color:var(--ink)">Temporary password for {{ $issuedFor }}</div>
                    <div style="font-size:var(--fs-xs);color:var(--ink2);margin:4px 0 12px;line-height:1.55">
                        Shown once and never stored in readable form. Give it to them directly, they must change it at next sign-in.
                    </div>
                    <div class="tnum" style="display:inline-block;padding:11px 16px;background:var(--surface);border:1px solid var(--border2);border-radius:10px;font-size:var(--fs-lg);font-weight:800;letter-spacing:.08em;color:var(--ink);user-select:all">{{ $issuedPassword }}</div>
                </div>
                <button class="btn-icon" wire:click="dismissIssued" title="Dismiss"><x-icon name="x" :size="17" /></button>
            </div>
        </div>
    @endif

    {{-- Toolbar ---------------------------------------------------------- --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px">
        <div class="search" style="width:340px;max-width:100%">
            <x-icon name="search" :size="15" />
            <input wire:model.live.debounce.200ms="q" placeholder="Search name, username or email…" class="input">
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:var(--fs-sm);color:var(--ink2);cursor:pointer">
            <input type="checkbox" wire:model.live="showRemoved" style="width:16px;height:16px;accent-color:var(--iris)">
            Show removed accounts
        </label>
    </div>

    {{-- Table ------------------------------------------------------------ --}}
    <div class="panel">
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr>
                        <th>Account</th><th>Role</th><th>Two-factor</th><th>Last sign-in</th><th>Status</th>
                        <th class="right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr @style(['opacity:.5' => $u->trashed()])>
                            <td>
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$u->name" :variant="$u->role?->tone === 'orange' ? 'orange' : 'navy'" :size="34" />
                                    <div style="min-width:0">
                                        <div style="font-weight:600;color:var(--ink)">{{ $u->name }}</div>
                                        <div class="tnum" style="font-size:var(--fs-xs);color:var(--muted)">{{ $u->username }} · {{ $u->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><x-ui.pill :tone="$u->role?->tone ?? 'iris'">{{ $u->roleLabel() }}</x-ui.pill></td>
                            <td>
                                @if ($u->hasTwoFactorEnabled())
                                    <x-ui.pill tone="paid" :dot="true">Enrolled</x-ui.pill>
                                @elseif ($u->requiresTwoFactor())
                                    <x-ui.pill tone="unpaid" :dot="true">Required</x-ui.pill>
                                @else
                                    <span style="font-size:var(--fs-xs);color:var(--faint)">Not required</span>
                                @endif
                            </td>
                            <td class="tnum" style="color:var(--muted);white-space:nowrap">
                                {{ $u->last_login_at ? Format::date($u->last_login_at) : 'Never' }}
                            </td>
                            <td>
                                @if ($u->trashed())
                                    <x-ui.pill tone="cancelled">Removed</x-ui.pill>
                                @elseif ($u->is_active)
                                    <x-ui.pill tone="paid" :dot="true">Active</x-ui.pill>
                                @else
                                    <x-ui.pill tone="unpaid" :dot="true">Inactive</x-ui.pill>
                                @endif
                            </td>
                            <td class="right" style="white-space:nowrap">
                                @if ($u->trashed())
                                    <button class="btn btn-ghost btn-sm" wire:click="askRestore({{ $u->id }})">
                                        <x-icon name="restore" :size="14" /> Restore
                                    </button>
                                    <button class="btn btn-ghost btn-sm" style="color:var(--over)" wire:click="askPurge({{ $u->id }})">
                                        <x-icon name="trash" :size="14" /> Purge
                                    </button>
                                @else
                                    <button class="btn btn-ghost btn-sm" wire:click="askResetPassword({{ $u->id }})" title="Issue a temporary password">
                                        <x-icon name="key" :size="14" />
                                    </button>
                                    <button class="btn btn-ghost btn-sm" wire:click="askResetTwoFactor({{ $u->id }})" title="Reset two-factor">
                                        <x-icon name="shield" :size="14" />
                                    </button>
                                    @if ($u->is_active)
                                        <button class="btn btn-ghost btn-sm" wire:click="impersonate({{ $u->id }})" title="View the portal as this user">
                                            <x-icon name="eye" :size="14" />
                                        </button>
                                    @endif
                                    {{-- Icon-only with titles: five text buttons per row overflowed
                                         the table on a laptop viewport, and an action you have to
                                         scroll sideways to find is an action nobody uses. --}}
                                    <button class="btn btn-ghost btn-sm" wire:click="askToggleActive({{ $u->id }})"
                                            title="{{ $u->is_active ? 'Deactivate this account' : 'Reactivate this account' }}">
                                        <x-icon name="{{ $u->is_active ? 'lock' : 'restore' }}" :size="14" />
                                    </button>
                                    <button class="btn btn-ghost btn-sm" style="color:var(--over)"
                                            wire:click="askRemove({{ $u->id }})" title="Remove this account">
                                        <x-icon name="trash" :size="14" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">No accounts match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('partials.danger-dialog')
</div>
