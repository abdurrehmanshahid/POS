<?php

use App\Services\DatabaseBackup;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Backup and export. On cPanel with no shell access this is the realistic
 * disaster-recovery path, so the copy tells the operator exactly how to restore
 * rather than leaving them to work it out during an incident.
 */
new #[Layout('components.layouts.super')] class extends Component {
    public function with(): array
    {
        $backup = app(DatabaseBackup::class);
        $summary = $backup->summary();

        return [
            'summary' => $summary,
            'totalRows' => array_sum($summary),
            'driver' => DB::connection()->getDriverName(),
            'database' => DB::connection()->getDatabaseName(),
        ];
    }
}; ?>

<div class="container-app anim-fade">

    {{-- Full dump ---------------------------------------------------------- --}}
    <div class="card" style="padding:22px 24px;margin-bottom:20px">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:13px;background:var(--iris-bg);flex:none">
                <x-icon name="database" :size="22" style="color:var(--iris)" />
            </div>
            <div style="flex:1;min-width:220px">
                <h3 style="font-size:16px;font-weight:800;color:var(--ink);margin:0 0 5px;letter-spacing:-.01em">Full database backup</h3>
                <p style="font-size:13px;color:var(--ink2);margin:0 0 6px;line-height:1.6">
                    A complete <strong>.sql</strong> file containing every table's structure and rows.
                    Generated in PHP and streamed, no shell access or <span class="tnum">mysqldump</span> binary required, which is
                    what makes it work on shared hosting.
                </p>
                <p class="tnum" style="font-size:12px;color:var(--muted);margin:0">
                    {{ count($summary) }} tables · {{ number_format($totalRows) }} rows · {{ $driver }} · {{ $database }}
                </p>
            </div>
            <a href="{{ route('superadmin.backups.sql') }}" class="btn btn-accent" style="flex:none;height:44px">
                <x-icon name="download" :size="17" /> Download .sql
            </a>
        </div>
    </div>

    {{-- Restore instructions ------------------------------------------------ --}}
    <div style="display:flex;gap:11px;padding:15px 18px;background:var(--info-bg);border:1px solid var(--border);border-radius:13px;margin-bottom:22px">
        <x-icon name="alert-circle" :size="17" style="color:var(--info);flex:none;margin-top:2px" />
        <div style="font-size:12.5px;color:var(--ink2);line-height:1.65">
            <strong style="color:var(--ink)">To restore:</strong> open <strong>phpMyAdmin</strong> in cPanel, select this database,
            go to <strong>Import</strong>, choose the .sql file and press Go. The file drops and recreates each table, so importing
            it replaces current data entirely, take a fresh backup first if the live data still matters.
            <br>
            <strong style="color:var(--ink)">Keep backups off this server.</strong> A dump sitting in the same hosting account is
            lost with the account. Download it somewhere else, and take one before every upgrade.
        </div>
    </div>

    {{-- Per-table CSV -------------------------------------------------------- --}}
    <div class="panel">
        <div class="panel-head">
            <x-icon name="download" :size="18" style="color:var(--navy2)" />
            <h3 class="panel-title">Per-table CSV export</h3>
        </div>
        <div class="scroll-x">
            <table class="table">
                <thead><tr><th>Table</th><th>Rows</th><th class="right">Export</th></tr></thead>
                <tbody>
                    @foreach ($summary as $table => $count)
                        <tr>
                            <td class="tnum" style="font-weight:600;color:var(--ink)">{{ $table }}</td>
                            <td class="tnum" style="color:{{ $count > 0 ? 'var(--ink2)' : 'var(--faint)' }}">{{ number_format($count) }}</td>
                            <td class="right">
                                @if ($count > 0)
                                    <a href="{{ route('superadmin.backups.csv', ['table' => $table]) }}" class="btn btn-ghost btn-sm">
                                        <x-icon name="download" :size="14" /> CSV
                                    </a>
                                @else
                                    <span style="font-size:12.5px;color:var(--faint)">empty</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
