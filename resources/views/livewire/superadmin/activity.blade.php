<?php

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The append-only activity log, searchable.
 *
 * Read-only by construction, not merely by omission. AuditLog::booted() throws
 * on update and delete, so there is no code path from this screen (or anywhere
 * else) that could quietly rewrite the record.
 */
new #[Layout('components.layouts.super')] class extends Component {
    use WithPagination;

    public string $q = '';

    public string $filter = 'all';

    /** Groups of actions, so an operator can jump to a class of event. */
    public const FILTERS = [
        'all' => ['All events', []],
        'security' => ['Security', ['Signed in', 'Sign-in failed', 'Two-factor failed', 'Two-factor enabled', 'Recovery code used', 'Super admin signed in', 'Super admin sign-in failed']],
        'money' => ['Money', ['Challan issued', 'Discount applied', 'Marked paid', 'Registration cancelled']],
        'people' => ['People', ['Student created', 'Student details edited', 'Staff account created', 'Role changed']],
        'destructive' => ['Destructive', ['Student removed', 'Student PURGED', 'Staff account removed', 'Staff account PURGED', 'Account deactivated', 'Password reset by admin', 'Password reset by super admin', 'Two-factor reset by super admin']],
        'impersonation' => ['Impersonation', ['Impersonation started', 'Impersonation ended']],
    ];

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $actions = self::FILTERS[$this->filter][1] ?? [];

        $rows = AuditLog::query()
            ->when($actions !== [], fn ($q) => $q->whereIn('action', $actions))
            ->when($this->q !== '', function ($q) {
                $term = '%'.Str::lower(trim($this->q)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(action) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(actor_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject_label) LIKE ?', [$term])
                    ->orWhere('ip_address', 'like', $term));
            })
            ->latest('id')
            ->paginate(40);

        return ['rows' => $rows, 'filters' => self::FILTERS];
    }
}; ?>

<div class="container-app anim-fade">

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px">
        <div class="search" style="width:320px;max-width:100%">
            <x-icon name="search" :size="15" />
            <input wire:model.live.debounce.250ms="q" placeholder="Search action, person, subject or IP…" class="input">
        </div>
        <div style="display:inline-flex;background:var(--surface3);border-radius:11px;padding:4px;gap:2px;flex-wrap:wrap">
            @foreach ($filters as $key => [$label, $_])
                <button wire:click="$set('filter','{{ $key }}')"
                        class="btn btn-sm {{ $filter === $key ? 'btn-primary' : '' }}"
                        style="{{ $filter === $key ? '' : 'background:transparent;color:var(--ink2)' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>Change</th><th>IP</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $destructive = Str::contains($row->action, ['PURGED', 'removed', 'deactivated', 'failed', 'reset']);
                        @endphp
                        <tr>
                            <td class="tnum" style="color:var(--muted);white-space:nowrap">
                                {{ $row->created_at?->format('d M y H:i') }}
                            </td>
                            <td style="white-space:nowrap">
                                @if ($row->actor_type === 'superadmin')
                                    <span style="display:inline-flex;align-items:center;gap:6px">
                                        <x-icon name="shield" :size="13" style="color:var(--iris)" />
                                        {{ $row->actor_name ?: 'Super admin' }}
                                    </span>
                                @else
                                    {{ $row->actorLabel() }}
                                @endif
                            </td>
                            <td style="font-weight:600;color:{{ $destructive ? 'var(--over)' : 'var(--ink)' }}">{{ $row->action }}</td>
                            <td class="tnum" style="color:var(--ink2)">{{ $row->subject_label ?: '' }}</td>
                            <td style="font-size:12.5px;color:var(--muted)">
                                @if ($row->old_value !== null || $row->new_value !== null)
                                    <span class="tnum">{{ $row->old_value ?: '' }}</span>
                                    <span style="color:var(--faint)"> → </span>
                                    <span class="tnum" style="color:var(--ink2);font-weight:600">{{ $row->new_value ?: '' }}</span>
                                @else, @endif
                            </td>
                            <td class="tnum" style="color:var(--faint);font-size:12px">{{ $row->ip_address ?: 'n/a' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">No events match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div style="padding:14px 18px;border-top:1px solid var(--border)">
                {{ $rows->links() }}
            </div>
        @endif
    </div>
</div>
