<?php

namespace App\Console\Commands;

use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--prune : Delete permissions that are no longer declared in config}
                            {--grant=* : Also hand an already existing permission (a name or a pattern such as messages.*) to the roles that hold it by default}';

    protected $description = 'Create permissions declared in config/permissions.php and give new ones to their default roles';

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf('Declared: %d   Added: %d', $declared->count(), $missing->count()));

        /*
         | A new right is useless until a role holds it, and that used to be a
         | separate, easily forgotten step: the screen it unlocks stayed hidden
         | from everybody but the Super Admin. So each newly created permission
         | goes to the roles that hold it by default.
         |
         | Additive only. Re-running the role seeder would put every role back
         | to its defaults and undo what an owner changed in the role editor;
         | this adds the new right and touches nothing else.
         |
         | `--grant` does the same for a right that already exists but never
         | reached the roles (one created by an earlier sync, before this step
         | existed).
         */
        $toGrant = $missing->values()
            ->merge($declared->filter(fn (string $name) => collect((array) $this->option('grant'))
                ->contains(fn (string $pattern) => Str::is($pattern, $name))))
            ->unique()
            ->values();

        if ($toGrant->isNotEmpty()) {
            $granted = $this->grantToDefaultRoles($toGrant->all(), $guard);
            $this->info(sprintf('Granted %d role assignment(s) for %d permission(s).', $granted, $toGrant->count()));
        }

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

    /**
     * @param  array<int, string>  $names
     */
    private function grantToDefaultRoles(array $names, string $guard): int
    {
        $seeder = new RolePermissionSeeder();
        $given = 0;

        foreach ($names as $name) {
            foreach ($seeder->defaultRolesFor($name) as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', $guard)->first();

                if ($role !== null && ! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                    $given++;
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $given;
    }
}
