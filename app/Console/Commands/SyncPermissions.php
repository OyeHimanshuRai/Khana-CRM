<?php

namespace App\Console\Commands;

use App\Support\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--prune : Delete permissions that are no longer declared in config}';

    protected $description = 'Create permissions declared in config/permissions.php';

    public function handle(): int
    {
        $guard = config('auth.defaults.guard', 'web');
        $declared = PermissionRegistry::names();

        $existing = Permission::where('guard_name', $guard)->pluck('name');
        $missing = $declared->diff($existing);
        $orphaned = $existing->diff($declared);

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        $this->info(sprintf('Declared: %d   Added: %d', $declared->count(), $missing->count()));

        if ($orphaned->isEmpty()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('%d permission(s) exist in the database but not in config:', $orphaned->count()));
        $orphaned->each(fn (string $name) => $this->line('  - '.$name));

        if (! $this->option('prune')) {
            $this->newLine();
            $this->line('Re-run with --prune to remove them.');
        } else {
            // Deleting cascades to role_has_permissions / model_has_permissions.
            Permission::where('guard_name', $guard)->whereIn('name', $orphaned)->delete();
            $this->info(sprintf('Pruned %d permission(s).', $orphaned->count()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }
}
