<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hand a restaurant's owner its own printers.
     *
     * A printer is a fact about the premises, but the Tenant Owner role held
     * none of `settings.printers.*`, so only the platform could add one, read
     * the print log or retry a failed bill. Printers are shop-scoped, so this
     * reaches nobody else's.
     *
     * Its own migration because the seeder only runs on a fresh install.
     */
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'Tenant Owner')->first(['id']);

        if ($role === null) {
            return;
        }

        // Empty until permissions:sync has run; the seeder assigns these too.
        $permissions = DB::table('permissions')->where('name', 'like', 'settings.printers.%')->pluck('id');

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

        // Written behind the package's back, so its cached copy is now wrong:
        // without this, owners would not see the screen for up to a day.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
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
