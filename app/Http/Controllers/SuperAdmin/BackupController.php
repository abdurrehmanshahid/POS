<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\DatabaseBackup;
use App\Support\Clock;
use App\Support\DownloadTicket;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams database backups and per-table CSVs.
 *
 * A full dump is the most sensitive artefact this system can produce, it
 * contains every password hash, every encrypted 2FA secret and the whole money
 * ledger in one file. So each download is itself an audited event: if a dump
 * ever leaves the building, the activity log says who took it, from where, and
 * exactly when.
 *
 * Auditing is forensics, though, not prevention. Holding a session is not the
 * same as being at the keyboard, so these routes additionally demand a ticket
 * that only a fresh step-up challenge can mint (see {@see DownloadTicket}).
 * Without it, exfiltrating the whole database from an unattended super-admin
 * session took fewer steps than deleting a single course.
 */
class BackupController extends Controller
{
    public function sql(Request $request, DatabaseBackup $backup): StreamedResponse
    {
        abort_unless(DownloadTicket::consume('sql'), 403, 'Confirm again to download a backup.');

        $actor = $request->user('superadmin');
        $filename = $backup->filename('sql');

        Audit::record('Database backup downloaded', $actor, [
            'subject_label' => $filename,
            'context' => ['tables' => count($backup->tables())],
        ]);

        return $backup->streamSqlDump($filename);
    }

    public function csv(Request $request, string $table, DatabaseBackup $backup): StreamedResponse
    {
        abort_unless(DownloadTicket::consume('csv:'.$table), 403, 'Confirm again to export a table.');

        $actor = $request->user('superadmin');

        // streamTableCsv 404s on anything outside the allow-list, so a crafted
        // table name cannot be used to read somewhere it should not.
        $filename = 'bbt-'.$table.'-'.Clock::now()->format('Y-m-d').'.csv';

        Audit::record('Table exported', $actor, [
            'subject_label' => $table,
            'context' => ['file' => $filename],
        ]);

        return $backup->streamTableCsv($table, $filename);
    }
}
