<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Declare the license permissions on a live install.
     *
     * The seeder only runs on a fresh database and `permissions:sync` is a manual
     * step, but the Super Admin's license screens are guarded by `permission:`
     * middleware that expects the rows to exist. They are created here, granted to
     * nobody: the Super Admin reaches them through Gate::before, and nobody else
     * should hold them.
     */
    public function up(): void
    {
        foreach (['settings.licenses.view', 'settings.licenses.edit'] as $name) {
            $exists = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['settings.licenses.view', 'settings.licenses.edit'])->delete();
    }
};
