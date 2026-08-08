<?php

use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        // Key-badge tone map (spec §9.9): PK → navy, FK → iris, UQ → orange.
        // Each field is [name, [keys], type]; keys is any of PK/FK/UQ.
        $tables = [
            'users' => [
                ['id', ['PK'], 'bigint'], ['name', [], 'varchar'], ['username', ['UQ'], 'varchar'],
                ['email', ['UQ'], 'varchar'], ['password', [], 'varchar'], ['role_id', ['FK'], 'varchar'],
                ['is_active', [], 'bool'], ['must_reset_password', [], 'bool'],
            ],
            'roles' => [
                ['id', ['PK'], 'varchar'], ['name', ['UQ'], 'varchar'], ['is_system', [], 'bool'], ['tone', [], 'enum'],
            ],
            'role_permissions' => [
                ['id', ['PK'], 'bigint'], ['role_id', ['FK'], 'varchar'], ['permission_key', [], 'varchar'],
            ],
            'students' => [
                ['id', ['PK'], 'bigint'], ['student_code', ['UQ'], 'varchar'], ['type', [], 'enum'],
                ['name', [], 'varchar'], ['guardian_name', [], 'varchar'], ['cnic', ['UQ'], 'varchar'],
                ['phone', [], 'varchar'], ['created_by', ['FK'], 'bigint'],
            ],
            'teachers' => [
                ['id', ['PK'], 'bigint'], ['name', [], 'varchar'], ['phone', [], 'varchar'],
            ],
            'courses' => [
                ['id', ['PK'], 'bigint'], ['code', ['UQ'], 'varchar'], ['title', [], 'varchar'],
                ['trainer_id', ['FK'], 'bigint'], ['fee', [], 'int'], ['capacity', [], 'int'], ['is_active', [], 'bool'],
            ],
            'admissions' => [
                ['id', ['PK'], 'bigint'], ['reg_no', ['UQ'], 'varchar'], ['student_id', ['FK'], 'bigint'],
                ['course_id', ['FK'], 'bigint'], ['enrolled_by', ['FK'], 'bigint'], ['status', [], 'enum'],
                ['rejection_reason', [], 'text'],
            ],
            'challans' => [
                ['id', ['PK'], 'bigint'], ['challan_no', ['UQ'], 'varchar'], ['admission_id', ['FK', 'UQ'], 'bigint'],
                ['base_amount', [], 'int'], ['discount_amount', [], 'int'], ['discount_reason', [], 'text'],
                ['discount_approved_by', ['FK'], 'bigint'], ['net_amount', [], 'int'], ['plan', [], 'enum'],
                ['due_date', [], 'date'], ['status', [], 'enum'], ['paid_via', [], 'varchar'], ['paid_at', [], 'datetime'],
            ],
            'installments' => [
                ['id', ['PK'], 'bigint'], ['challan_id', ['FK'], 'bigint'], ['seq', [], 'tinyint'],
                ['amount', [], 'int'], ['due_date', [], 'date'], ['status', [], 'enum'], ['paid_at', [], 'datetime'],
            ],
            'audit_logs' => [
                ['id', ['PK'], 'bigint'], ['challan_id', ['FK'], 'bigint'], ['actor_id', ['FK'], 'bigint'],
                ['action', [], 'varchar'], ['field', [], 'varchar'], ['old_value', [], 'varchar'],
                ['new_value', [], 'varchar'], ['created_at', [], 'timestamp'],
            ],
            'settings' => [
                ['id', ['PK'], 'bigint'], ['name', [], 'varchar'], ['bank', [], 'varchar'], ['account', [], 'varchar'],
                ['iban', [], 'varchar'], ['next_challan_serial', [], 'int'], ['twofa_required', [], 'bool'],
            ],
        ];

        return [
            'tables' => $tables,
            'toneOf' => ['PK' => 'navy', 'FK' => 'iris', 'UQ' => 'orange'],
        ];
    }
}; ?>

<div class="container-app anim-fade">
    <div style="margin-bottom:18px">
        <h2 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);letter-spacing:-.02em;margin:0">Data model</h2>
        <p style="font-size:var(--fs-sm);color:var(--muted);margin:5px 0 0">The eleven tables behind every screen · one source of truth</p>
    </div>

    {{-- Intro banner --}}
    <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:16px;padding:18px 22px;color:#fff;box-shadow:var(--sh2);margin-bottom:22px">
        <p style="margin:0;font-size:var(--fs-sm);line-height:1.7;color:#dfe1f5">
            <strong style="color:#fff;font-weight:700">users → roles → role_permissions</strong> drive who can see and do what.
            A <strong style="color:#fff;font-weight:700">student</strong> is created once and reused across enrolments; each
            <strong style="color:#fff;font-weight:700">admission</strong> stores <strong style="color:#fff;font-weight:700">enrolled_by</strong>, the source
            of truth for who registered whom. Exactly one <strong style="color:#fff;font-weight:700">challan</strong> per admission carries the
            derived money (net = base − discount, never hand-typed), and every change is written to an append-only
            <strong style="color:#fff;font-weight:700">audit_log</strong>.
        </p>
    </div>

    {{-- Entity cards --}}
    <div class="grid-2">
        @foreach ($tables as $table => $fields)
            <div class="card" style="padding:0;overflow:hidden;align-self:start">
                <div style="padding:12px 18px;border-bottom:1px solid var(--border);background:var(--surface2)">
                    <span style="font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:var(--fs-sm);font-weight:800;color:var(--navy);letter-spacing:.01em">{{ $table }}</span>
                </div>
                <div style="padding:4px 18px 10px">
                    @foreach ($fields as [$field, $keys, $type])
                        <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--surface3)">
                            <span style="font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:var(--fs-xs);font-weight:600;color:var(--ink2)">{{ $field }}</span>
                            @foreach ($keys as $k)
                                <x-ui.pill tone="{{ $toneOf[$k] }}" style="font-size:var(--fs-3xs);padding:1px 6px;font-weight:800">{{ $k }}</x-ui.pill>
                            @endforeach
                            <span style="margin-left:auto;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:var(--fs-2xs);color:var(--muted)">{{ $type }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
