<?php

use App\Models\AppNotification;
use Livewire\Volt\Component;

new class extends Component {
    public function markAll(): void
    {
        AppNotification::visibleTo(auth()->user())->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function openNotif(int $id)
    {
        $n = AppNotification::visibleTo(auth()->user())->find($id);
        if (! $n) {
            return null;
        }
        $n->update(['read_at' => now()]);
        if ($n->challan_id) {
            return $this->redirectRoute('challans', ['open' => $n->challan_id], navigate: true);
        }

        return null;
    }

    public function with(): array
    {
        $notifs = AppNotification::visibleTo(auth()->user())->latest()->get();

        return ['notifs' => $notifs, 'unread' => $notifs->whereNull('read_at')->count()];
    }
}; ?>

@php
    $meta = [
        'overdue'  => ['!', 'var(--over-bg)', 'var(--over)'],
        'payment'  => ['✓', 'var(--paid-bg)', 'var(--paid)'],
        'enrol'    => ['+', 'var(--info-bg)', 'var(--info)'],
        'capacity' => ['▲', 'var(--due-bg)', 'var(--due)'],
        'system'   => ['i', 'var(--iris-bg)', 'var(--iris)'],
    ];
@endphp

<div style="position:relative;flex:0 0 auto" x-data="{ open: false }">
    <button class="btn-icon" title="Notifications" style="position:relative" @click="open = !open">
        <x-icon name="bell" :size="18" />
        @if ($unread > 0)
            <span class="tnum" style="position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;background:var(--orange);color:#3d2600;border-radius:9px;border:2px solid var(--surface);font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center">{{ $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" class="menu"
         style="top:calc(100% + 10px);right:0;width:370px;max-width:88vw">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--surface3)">
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:14px;font-weight:800;color:var(--ink)">Notifications</span>
                @if ($unread > 0)
                    <span class="tnum" style="padding:1px 8px;border-radius:999px;background:var(--orange-bg);color:var(--orange);font-size:10.5px;font-weight:800">{{ $unread }} new</span>
                @endif
            </div>
            <button wire:click="markAll" style="border:none;background:transparent;color:var(--iris);font-size:12px;font-weight:700;cursor:pointer">Mark all read</button>
        </div>
        <div style="max-height:400px;overflow-y:auto">
            @forelse ($notifs as $n)
                @php [$ic, $bg, $col] = $meta[$n->type] ?? $meta['system']; @endphp
                <div wire:click="openNotif({{ $n->id }})" style="display:flex;gap:12px;padding:13px 16px;border-bottom:1px solid var(--surface3);cursor:pointer;{{ $n->read_at ? '' : 'background:color-mix(in srgb, var(--orange-bg) 40%, transparent)' }}">
                    <div style="width:32px;height:32px;flex:0 0 auto;border-radius:9px;background:{{ $bg }};display:flex;align-items:center;justify-content:center;color:{{ $col }};font-size:14px;font-weight:800">{{ $ic }}</div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:700;color:var(--ink)">{{ $n->title }}</div>
                        <div style="font-size:12px;color:var(--muted);margin-top:1px">{{ $n->sub }}</div>
                        <div style="font-size:11px;color:var(--faint);margin-top:3px">{{ $n->created_at?->diffForHumans() }}</div>
                    </div>
                    @unless ($n->read_at)
                        <span style="width:8px;height:8px;flex:0 0 auto;border-radius:50%;background:var(--orange);margin-top:5px"></span>
                    @endunless
                </div>
            @empty
                <div style="padding:30px 16px;text-align:center;font-size:12.5px;color:var(--faint)">You're all caught up.</div>
            @endforelse
        </div>
    </div>
</div>
