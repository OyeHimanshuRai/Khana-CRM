<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hand the kitchen performance report to the roles that already read the
     * other reports (§9).
     *
     * Its own migration rather than an edit to the one beside it, because
     * that one has already run wherever this is being upgraded and an edited
     * migration is a migration that does nothing.
     *
     * Deliberately narrow. Every role listed here already holds
     * `reports.sales_report.view` or the whole `reports.` prefix, so this
     * grants nobody a new kind of access - it only keeps a role that was given
     * "the reports" from silently missing the one added after it was created.
     * A cook is not on the list: a line cook has no use for a report about how
     * slow the line was, and the head chef who does have Manager or better.
     */
    public function up(): void
    {
        $roles = ['Admin', 'Tenant Owner', 'Shop Admin', 'Manager', 'Accountant', 'Auditor'];

        $readOnly = ['reports.kitchen_report.view', 'reports.kitchen_report.export',
            'reports.kitchen_report.print', 'reports.kitchen_report.download'];

        $permissions = DB::table('permissions')->whereIn('name', $readOnly)->pluck('id');

        if ($permissions->isEmpty()) {
            // permissions:sync has not run yet on this install. It will, and
            // the seeder assigns these the same way - nothing to do here.
            return;
        }

        foreach ($roles as $name) {
            $role = DB::table('roles')->where('name', $name)->first(['id']);

            if ($role === null) {
                continue;
            }

            foreach ($permissions as $permissionId) {
                $held = DB::table('role_has_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $held) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    /**
     * Not reversed: there is no record here of which grants this added as
     * against which ones were ticked on by hand afterwards.
     */
    public function down(): void
    {
        // Deliberately empty. See the note on up().
    }
};
