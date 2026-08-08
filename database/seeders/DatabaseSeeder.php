<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // Demo data is LOCAL AND TEST ONLY.
        //
        // It creates a fictional institute: nine courses, ~30 students with
        // invented CNICs, their challans and collections, and three working
        // logins whose passwords are committed to this repository and printed
        // in the README. On a production install that is not sample data, it is
        // a set of live back doors sitting beside the client's real money, and
        // its fake revenue silently contaminates every figure on the dashboard.
        //
        // It was previously called unconditionally, and docs/DEPLOYMENT.md told
        // the operator to run `db:seed --force` on the live server. The guard
        // lives here rather than in the seeder so that running it deliberately
        // by name still works for anyone building a demo environment.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
