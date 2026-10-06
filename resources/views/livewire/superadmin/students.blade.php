<?php

use App\Models\Student;
use App\Services\RecordRemoval;
use App\Services\StudentFreezes;
use App\Support\Concerns\ConfirmsDangerously;
use App\Support\Format;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Every student record, including removed ones, the institute portal only ever
 * shows live records, so this is the one place a soft-deleted student can be
 * seen and brought back.
 */
new #[Layout('components.layouts.super')] class extends Component {
    use ConfirmsDangerously;

    public string $q = '';

    public bool $showRemoved = false;

    private function actor()
    {
        return auth()->guard('superadmin')->user();
    }

    private function target(): ?Student
    {
        return $this->dangerId ? Student::withTrashed()->find($this->dangerId) : null;
    }

    public function with(): array
    {
        $students = Student::withTrashed()
            ->withCount(['admissions' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->with(['admissions.challan.payments'])
            ->withCharges()
            ->when(! $this->showRemoved, fn ($q) => $q->whereNull('deleted_at'))
            ->when($this->q !== '', function ($q) {
                $term = '%'.Str::lower(trim($this->q)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(student_code) LIKE ?', [$term])
                    ->orWhere('cnic', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('student_code')
            ->get();

        return [
            'students' => $students,
            'removal' => app(RecordRemoval::class),
        ];
    }

    public function askRemove(int $id): void
    {
        $s = Student::findOrFail($id);

        $this->askDanger([
            'kind' => 'remove',
            'id' => $s->id,
            'title' => 'Remove '.$s->name,
            'body' => 'The student disappears from the directory and every staff screen. Their admissions, challans and audit history are kept and the record can be restored. This is reversible.',
            'phrase' => $s->student_code ?? $s->name,
            'confirmLabel' => 'Remove student',
            'needsReason' => true,
        ]);
    }

    public function askRestore(int $id): void
    {
        $s = Student::withTrashed()->findOrFail($id);

        $this->askDanger([
            'kind' => 'restore',
            'id' => $s->id,
            'title' => 'Restore '.$s->name,
            'body' => 'The record returns to the directory with its enrolments and fee history intact.',
            'confirmLabel' => 'Restore student',
        ]);
    }

    public function askFreeze(int $id): void
    {
        $s = Student::findOrFail($id);

        $this->askDanger([
            'kind' => 'freeze',
            'id' => $s->id,
            'title' => 'Freeze '.$s->name,
            'body' => 'Puts the student on hold. Their courses and fees stay as they are and they come off the attendance register. When unfrozen, unpaid fee deadlines move forward by the days they were frozen.',
            'confirmLabel' => 'Freeze student',
            'needsReason' => true,
        ]);
    }

    public function askUnfreeze(int $id): void
    {
        $s = Student::findOrFail($id);

        $this->askDanger([
            'kind' => 'unfreeze',
            'id' => $s->id,
            'title' => 'Unfreeze '.$s->name,
            'body' => 'The student goes back on the attendance register, and every unpaid fee deadline that had not passed when they were frozen moves forward by the days they were frozen.',
            'confirmLabel' => 'Unfreeze student',
        ]);
    }

    public function askPurge(int $id): void
    {
        $s = Student::withTrashed()->findOrFail($id);
        $removal = app(RecordRemoval::class);

        // Checked before the dialog opens, so a blocked purge costs no typing.
        if ($blocker = $removal->purgeBlocker($s)) {
            $this->dispatch('bbt-toast', tone: 'warn', title: 'Cannot purge', msg: $blocker);

            return;
        }

        $this->askDanger([
            'kind' => 'purge',
            'id' => $s->id,
            'title' => 'Permanently destroy '.$s->name,
            'body' => 'Deletes the student and any unpaid admissions and challans outright. This cannot be undone. A full snapshot is written to the audit log first.',
            'phrase' => $s->student_code ?? $s->name,
            'irreversible' => true,
            'confirmLabel' => 'Purge permanently',
            'needsReason' => true,
        ]);
    }

    public function confirmDanger(): void
    {
        if (! $this->dangerCleared($this->actor())) {
            return;
        }

        $s = $this->target();
        if (! $s) {
            $this->dangerError = 'That student no longer exists.';

            return;
        }

        $removal = app(RecordRemoval::class);
        $freezes = app(StudentFreezes::class);
        $kind = $this->dangerKind;
        $name = $s->name;

        try {
            $result = match ($kind) {
                'remove' => $removal->remove($s, $this->actor(), trim($this->dangerReason)),
                'restore' => $removal->restore($s, $this->actor()),
                'purge' => $removal->purge($s, $this->actor(), trim($this->dangerReason)),
                'freeze' => $freezes->freeze($this->actor(), $s, $this->dangerReason),
                'unfreeze' => $freezes->unfreeze($this->actor(), $s),
                default => null,
            };
        } catch (\RuntimeException $e) {
            $this->dangerError = $e->getMessage();

            return;
        }

        $this->closeDanger();
        $this->dispatch('bbt-toast',
            tone: $kind === 'purge' ? 'err' : 'ok',
            title: match ($kind) {
                'remove' => 'Student removed',
                'restore' => 'Student restored',
                'purge' => 'Student destroyed',
                'freeze' => 'Student frozen',
                'unfreeze' => 'Student unfrozen',
                default => 'Done',
            },
            msg: $kind === 'unfreeze'
                ? $name.' · '.$result['moved'].' fee '.($result['moved'] === 1 ? 'deadline' : 'deadlines').' moved forward '.$result['days'].' '.($result['days'] === 1 ? 'day' : 'days')
                : $name,
        );
    }
}; ?>

<div class="container-app anim-fade">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px">
        <div class="search" style="width:340px;max-width:100%">
            <x-icon name="search" :size="15" />
            <input wire:model.live.debounce.200ms="q" placeholder="Search ID, name, CNIC or phone…" class="input">
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:var(--fs-sm);color:var(--ink2);cursor:pointer">
            <input type="checkbox" wire:model.live="showRemoved" style="width:16px;height:16px;accent-color:var(--iris)">
            Show removed students
        </label>
    </div>

    <div class="panel">
        <div class="scroll-x">
            <table class="table">
                <thead>
                    <tr><th>ID</th><th>Student</th><th>CNIC</th><th>Courses</th><th>Outstanding</th><th>Status</th><th class="right actions-col">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($students as $s)
                        @php
                            $outstanding = $s->outstanding();
                            $blocker = $removal->purgeBlocker($s);
                        @endphp
                        <tr @style(['opacity:.5' => $s->trashed()])>
                            <td class="tnum" style="color:var(--iris);font-weight:700">{{ $s->codeLabel() }}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:11px">
                                    <x-ui.avatar :name="$s->name" variant="orange" :size="30" />
                                    <div style="min-width:0">
                                        <div style="font-weight:600;color:var(--ink)">{{ $s->name }}</div>
                                        <div style="font-size:var(--fs-xs);color:var(--muted)">{{ $s->guardian_name }} · {{ $s->typeLabel() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="tnum">{{ $s->cnic }}</td>
                            <td class="tnum">{{ $s->admissions_count }}</td>
                            <td class="tnum" style="font-weight:700;color:{{ $outstanding > 0 ? 'var(--due)' : 'var(--muted)' }}">
                                {{ $outstanding > 0 ? Format::money($outstanding) : '' }}
                            </td>
                            <td>
                                @if ($s->trashed())
                                    <x-ui.pill tone="cancelled">Removed</x-ui.pill>
                                @elseif ($s->isFrozen())
                                    <x-ui.pill tone="iris" title="{{ $s->freeze_reason }}">Frozen since {{ Format::date($s->frozen_at) }}</x-ui.pill>
                                @else
                                    <x-ui.pill tone="paid" :dot="true">Active</x-ui.pill>
                                @endif
                            </td>
                            <td class="right actions-col">
                                @if ($s->trashed())
                                    <button class="btn btn-ghost btn-sm" wire:click="askRestore({{ $s->id }})">
                                        <x-icon name="restore" :size="14" /> Restore
                                    </button>
                                @else
                                    @if ($s->isFrozen())
                                        <button class="btn btn-ghost btn-sm" wire:click="askUnfreeze({{ $s->id }})">
                                            <x-icon name="restore" :size="14" /> Unfreeze
                                        </button>
                                    @elseif (! $s->isWalkIn())
                                        <button class="btn btn-ghost btn-sm" wire:click="askFreeze({{ $s->id }})">
                                            <x-icon name="clock" :size="14" /> Freeze
                                        </button>
                                    @endif
                                    <button class="btn btn-ghost btn-sm" style="color:var(--over)" wire:click="askRemove({{ $s->id }})">
                                        <x-icon name="trash" :size="14" /> Remove
                                    </button>
                                @endif
                                <button class="btn btn-ghost btn-sm"
                                        style="color:{{ $blocker ? 'var(--faint)' : 'var(--over)' }}"
                                        wire:click="askPurge({{ $s->id }})"
                                        title="{{ $blocker ?: 'Permanently destroy this record' }}">
                                    <x-icon name="trash" :size="14" /> Purge
                                </button>
                            </td>
                        </tr>
                    @empty
                        <x-ui.table-empty :cols="7" target="q,showRemoved">No students match.</x-ui.table-empty>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('partials.danger-dialog')
</div>
