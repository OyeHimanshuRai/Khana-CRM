<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing branch the `kitchen` module, and the roles that
     * work a kitchen the rights to use it.
     *
     * The module half is the same argument as the `dining` one before it: a
     * branch configured before `kitchen` existed was asked about the modules
     * that existed then, and cannot have said no to one that did not. Leaving
     * those shops alone would take the KDS dark for every existing branch on
     * upgrade, which reads as the feature being broken rather than off. Shops
     * on `null` are untouched, because null already means everything.
     *
     * The roles half is new, and it is here rather than in the seeder because
     * the seeder only runs on a fresh install. A restaurant that has been
     * live for a month would otherwise upgrade into a kitchen screen its head
     * chef gets a 403 on, and the fix - open the roles screen and tick eight
     * boxes - is one nobody knows they need to make.
     */
    public function up(): void
    {
        $this->addKitchenModule();
        $this->grantKitchenRights();
    }

    private function addKitchenModule(): void
    {
        $shops = DB::table('shops')
            ->whereNotNull('modules')
            ->get(['id', 'modules']);

        foreach ($shops as $shop) {
            $modules = json_decode((string) $shop->modules, true);

            // Not an array means the column holds something this migration
            // does not understand. Leaving it exactly as it is beats guessing.
            if (! is_array($modules) || in_array('kitchen', $modules, true)) {
                continue;
            }

            $modules[] = 'kitchen';

            DB::table('shops')
                ->where('id', $shop->id)
                ->update(['modules' => json_encode(array_values($modules))]);
        }
    }

    /**
     * Hand the new rights to the roles that already do the neighbouring job.
     *
     * Matched by name, which is how these roles are created, and skipped
     * silently where a name is absent - a tenant that renamed its roles keeps
     * whatever it chose, and gets nothing done to it by a migration guessing.
     *
     * `recall` is granted narrowly on purpose: it rewrites the timestamps the
     * preparation-time report is built from, which is §9's "reopen only for
     * authorized staff". A cook gets the board and the bump; sending a ticket
     * back is a manager's.
     */
    private function grantKitchenRights(): void
    {
        $grants = [
            'Admin' => ['view', 'advance', 'recall', 'print', 'stations'],
            'Tenant Owner' => ['view', 'advance', 'recall', 'print', 'stations'],
            'Shop Admin' => ['view', 'advance', 'recall', 'print', 'stations'],
            'Manager' => ['view', 'advance', 'recall', 'print', 'stations'],
            'Employee' => ['view', 'advance', 'print'],
            // The counter watches the pass and reprints slips; it does not
            // mark food ready. See the note in RolePermissionSeeder.
            'Cashier' => ['view', 'print'],
            'Auditor' => ['view', 'print'],
            /*
             | The two roles §12 names. They are created by
             | RolePermissionSeeder, which an existing install may not have
             | re-run yet - so they are listed here and skipped silently when
             | absent, and picked up whenever the seeder does run.
             */
            'Kitchen Staff' => ['view', 'advance', 'print'],
            'Captain / Waiter' => ['view', 'advance'],
        ];

        foreach ($grants as $roleName => $actions) {
            $role = DB::table('roles')->where('name', $roleName)->first(['id']);

            if ($role === null) {
                continue;
            }

            $names = [];

            foreach ($actions as $action) {
                $names[] = $action === 'stations'
                    ? ['kitchen.stations.view', 'kitchen.stations.create', 'kitchen.stations.edit', 'kitchen.stations.delete']
                    : ['kitchen.tickets.'.$action];
            }

            $names = array_merge(...$names);

            $permissions = DB::table('permissions')->whereIn('name', $names)->pluck('id');

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
     * Not reversed.
     *
     * Taking `kitchen` back off would switch the board dark for branches that
     * have been cooking off it, and there is no record here of which shops
     * this added it to as against which ones ticked it themselves.
     */
    public function down(): void
    {
        // Deliberately empty. See the note on up().
    }
};
