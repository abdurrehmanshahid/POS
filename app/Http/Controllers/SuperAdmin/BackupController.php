<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\DatabaseBackup;
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
 */
class BackupController extends Controller
{
    public function sql(Request $request, DatabaseBackup $backup): StreamedResponse
    {
        $actor = $request->user('superadmin');
        $filename = 'bbt-backup-'.now()->format('Y-m-d-His').'.sql';

        Audit::record('Database backup downloaded', $actor, [
            'subject_label' => $filename,
            'context' => ['tables' => count($backup->tables())],
        ]);

        return $backup->streamSqlDump($filename);
    }

    public function csv(Request $request, string $table, DatabaseBackup $backup): StreamedResponse
    {
        $actor = $request->user('superadmin');

        // streamTableCsv 404s on anything outside the allow-list, so a crafted
        // table name cannot be used to read somewhere it should not.
        $filename = 'bbt-'.$table.'-'.now()->format('Y-m-d').'.csv';

        Audit::record('Table exported', $actor, [
            'subject_label' => $table,
            'context' => ['file' => $filename],
        ]);

        return $backup->streamTableCsv($table, $filename);
    }
}
