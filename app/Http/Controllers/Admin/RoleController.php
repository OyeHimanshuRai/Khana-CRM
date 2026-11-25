<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Roles that must always exist and keep their name.
     *
     * Super Admin additionally cannot have its permissions edited - it draws
     * its access from Gate::before, so an empty permission set would be
     * misleading rather than restrictive.
     */
    private const PROTECTED_ROLES = [User::SUPER_ADMIN];

    /** Roles that belong to the platform itself, not to a restaurant's staff. */
    private const PLATFORM_ROLES = [User::SUPER_ADMIN];

    public function index(Request $request): View
    {
        /*
         | The platform's own roles come first and alone; the restaurant staff
         | templates (Tenant Owner, Manager, Cashier...) sit one tab over, so
         | the Super Admin is not looking at a restaurant's roles unless they
         | go there on purpose.
         */
        $scope = $request->query('scope') === 'restaurant' ? 'restaurant' : 'platform';

        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->when(
                $scope === 'platform',
                fn ($q) => $q->whereIn('name', self::PLATFORM_ROLES),
                fn ($q) => $q->whereNotIn('name', self::PLATFORM_ROLES),
            )
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->get();

        return view('admin.roles.index', [
            'roles' => $roles,
            'totalPermissions' => PermissionRegistry::count(),
            // What the Super Admin role covers: the platform's own abilities, not a restaurant's.
            'platformPermissions' => PermissionRegistry::split()['platform']->sum('count'),
            'search' => $request->string('q')->toString(),
            'scope' => $scope,
        ]);
    }

    public function create(): View
    {
        $split = PermissionRegistry::split();

        return view('admin.roles.form', [
            'role' => new Role(),
            'tree' => $split['restaurant'],
            'platformTree' => $split['platform'],
            'granted' => [],
            'locked' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => config('auth.defaults.guard', 'web'),
            ]);

            $role->syncPermissions($this->cleanPermissions($data['permissions'] ?? []));

            return $role;
        });

        ActivityLog::record('role.created', "Created role \"{$role->name}\"", $role, [
            'permissions' => $role->permissions->count(),
        ]);

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('status', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role): View
    {
        $split = PermissionRegistry::split();
        $locked = $this->isProtected($role);

        /*
         | Super Admin is the platform's own role: it is shown the platform's abilities
         | (all of them, ticked and locked - it draws them from Gate::before, not from
         | stored rows) and none of a restaurant's till, kitchen or stock. Every other
         | role is shown the restaurant's abilities, with the platform-only ones in a
         | separate section: they matter only to platform staff, never to a company's.
         */
        return view('admin.roles.form', [
            'role' => $role,
            'tree' => $locked ? $split['platform'] : $split['restaurant'],
            'platformTree' => $locked ? null : $split['platform'],
            'granted' => $locked
                ? $split['platform']->flatMap(fn (array $m) => $m['names'])->all()
                : $role->permissions->pluck('name')->all(),
            'locked' => $locked,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($this->isProtected($role), 403, 'The Super Admin role cannot be modified.');

        $data = $this->validated($request, $role);
        $before = $role->permissions->pluck('name')->sort()->values()->all();

        DB::transaction(function () use ($request, $role, $data) {
            $role->update(['name' => $data['name']]);

            // The rename form does not carry the matrix. Syncing on its
            // absence would silently strip every permission from the role.
            if ($request->has('permissions')) {
                $role->syncPermissions($this->cleanPermissions($data['permissions'] ?? []));
            }
        });

        $after = $role->fresh()->permissions->pluck('name')->sort()->values()->all();

        ActivityLog::record('role.updated', "Updated role \"{$role->name}\"", $role, [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]);

        return back()->with('status', "Role \"{$role->name}\" updated.");
    }

    /**
     * Save the permission matrix without a page reload.
     */
    public function sync(Request $request, Role $role): JsonResponse
    {
        if ($this->isProtected($role)) {
            return ApiResponse::error('The Super Admin role cannot be modified.', [], 403);
        }

        $data = $request->validate([
            'permissions' => ['array'],
            // The matrix always submits one blank entry so the key is present
            // even with nothing ticked; ConvertEmptyStringsToNull turns that
            // into null, hence `nullable`. cleanPermissions() discards it.
            'permissions.*' => ['nullable', 'string'],
        ]);

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $permissions = $this->cleanPermissions($data['permissions'] ?? []);

        $role->syncPermissions($permissions);

        $after = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        ActivityLog::record('role.permissions_synced', "Updated permissions for \"{$role->name}\"", $role, [
            'added' => $added,
            'removed' => $removed,
        ]);

        return ApiResponse::success(
            sprintf(
                '%d permission%s saved for "%s".',
                count($after),
                count($after) === 1 ? '' : 's',
                $role->name
            ),
            [
                'total' => count($after),
                'added' => count($added),
                'removed' => count($removed),
            ]
        );
    }

    /**
     * Duplicate a role along with its permission set.
     */
    public function clone(Role $role): RedirectResponse
    {
        $copy = DB::transaction(function () use ($role) {
            $copy = Role::create([
                'name' => $this->uniqueName($role->name),
                'guard_name' => $role->guard_name,
            ]);

            // Super Admin holds no rows of its own, so a clone of it would be
            // an empty role. Copy the full declared set instead.
            $permissions = $this->isProtected($role)
                ? PermissionRegistry::names()->all()
                : $role->permissions->pluck('name')->all();

            $copy->syncPermissions($permissions);

            return $copy;
        });

        ActivityLog::record('role.cloned', "Cloned \"{$role->name}\" into \"{$copy->name}\"", $copy);

        return redirect()
            ->route('admin.roles.edit', $copy)
            ->with('status', "Role cloned as \"{$copy->name}\".");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($this->isProtected($role), 403, 'The Super Admin role cannot be deleted.');

        if ($role->users()->exists()) {
            return back()->with('error', "\"{$role->name}\" still has users assigned and cannot be deleted.");
        }

        $name = $role->name;
        $role->delete();

        ActivityLog::record('role.deleted', "Deleted role \"{$name}\"");

        return redirect()
            ->route('admin.roles.index', ['scope' => 'restaurant'])
            ->with('status', "Role \"{$name}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('roles', 'name')->ignore($role?->id),
                // Guard against creating a second privileged role by name.
                Rule::notIn($role && $this->isProtected($role) ? [] : self::PROTECTED_ROLES),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['nullable', 'string'],
        ], [
            'name.not_in' => 'That role name is reserved.',
        ]);
    }

    /**
     * Drop anything not declared in config, so a tampered form cannot create
     * arbitrary permission rows. Also discards the matrix's blank placeholder
     * entry, which arrives as null.
     *
     * @param  array<int, string|null>  $permissions
     * @return array<int, string>
     */
    private function cleanPermissions(array $permissions): array
    {
        $declared = PermissionRegistry::names();

        return collect($permissions)
            ->filter(fn ($name) => is_string($name) && $declared->contains($name))
            ->unique()
            ->values()
            ->all();
    }

    private function isProtected(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_ROLES, true);
    }

    private function uniqueName(string $base): string
    {
        $candidate = "{$base} (Copy)";
        $suffix = 2;

        while (Role::where('name', $candidate)->exists()) {
            $candidate = "{$base} (Copy {$suffix})";
            $suffix++;
        }

        return $candidate;
    }
}
