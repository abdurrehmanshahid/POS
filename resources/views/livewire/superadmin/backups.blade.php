<?php

use App\Services\DatabaseBackup;
use App\Support\Concerns\ConfirmsDangerously;
use App\Support\DownloadTicket;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Backup and export. On cPanel with no shell access this is the realistic
 * disaster-recovery path, so the copy tells the operator exactly how to restore
 * rather than leaving them to work it out during an incident.
 *
 * Both downloads are gated by the same step-up dialog as a record purge. A dump
 * carries every password hash and encrypted 2FA secret in the system, so taking
 * one should cost at least as much friction as deleting one row.
 */
new #[Layout('components.layouts.super')] class extends Component {
    use ConfirmsDangerously;

    /** Which artefact the open dialog authorises, '' | 'sql' | 'csv:<table>'. */
    public string $pendingRef = '';

    private function actor()
    {
        return auth()->guard('superadmin')->user();
    }

    public function askSql(): void
    {
        $this->pendingRef = 'sql';
        $this->askDanger([
            'kind' => 'download',
            'title' => 'Download full database backup',
            'body' => 'The .sql file contains every table in this system, including staff password hashes, encrypted two-factor secrets and the entire money ledger. Anyone who obtains the file obtains the institute. Store it off this server and treat it like cash.',
            'confirmLabel' => 'Download backup',
        ]);
    }

    public function askCsv(string $table): void
    {
        $this->pendingRef = 'csv:'.$table;
        $this->askDanger([
            'kind' => 'download',
            'title' => 'Export '.$table,
            'body' => 'Every row of the '.$table.' table leaves the system as a CSV file. The export is recorded against your name in the activity log.',
            'confirmLabel' => 'Export table',
        ]);
    }

    public function confirmDanger(): void
    {
        if (! $this->dangerCleared($this->actor())) {
            return;
        }

        $ref = $this->pendingRef;
        $this->closeDanger();
        $this->pendingRef = '';

        // The ticket names one artefact and dies on first use, so the redirect
        // below is the only request that can ever spend it.
        DownloadTicket::issue($ref);

        $url = $ref === 'sql'
            ? route('superadmin.backups.sql')
            : route('superadmin.backups.csv', ['table' => substr($ref, 4)]);

        // navigate:false on purpose, a SPA fetch cannot save a file to disk.
        $this->redirect($url, navigate: false);
    }

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
            <button wire:click="askSql" class="btn btn-accent" style="flex:none;height:44px">
                <x-icon name="download" :size="17" /> Download .sql
            </button>
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
                                    <button wire:click="askCsv('{{ $table }}')" class="btn btn-ghost btn-sm">
                                        <x-icon name="download" :size="14" /> CSV
                                    </button>
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

    @include('partials.danger-dialog')
</div>
