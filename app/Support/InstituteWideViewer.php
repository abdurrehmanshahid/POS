<?php

namespace App\Support;

use App\Models\User;
use LogicException;

/**
 * A read-only stand-in for "whoever may see the whole institute".
 *
 * Reporting, Ledger and ReportBook scope every figure by asking the user they
 * are handed `hasPermission('scope.all')` and `can('revenue.view')`. The super
 * admin is not a staff User (it lives on its own guard and table), so its
 * Reports tab hands them this instead: it holds every permission, so each
 * query takes its institute-wide branch and never reads `id`.
 *
 * Never persisted. It exists only to answer those questions, and a save would
 * create a staff account that nobody chose to create.
 */
final class InstituteWideViewer extends User
{
    public function hasPermission(string $key): bool
    {
        return true;
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('InstituteWideViewer is a read-only stand-in and cannot be saved.');
    }
}
