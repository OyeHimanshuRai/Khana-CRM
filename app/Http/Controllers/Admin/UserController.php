<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use App\Support\PermissionRegistry;
use App\Support\PlanAccess;
use App\Support\WelcomeMailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /** Page sizes offered by the "entries per page" control. */
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $users = $this->filtered($request)
            ->with('roles')
            ->withCount([
                'permissions',
                // Drives the "Sessions" column without an N+1 per row.
                'sessions as active_sessions_count' => fn (Builder $query) => $query->whereNull('logout_at'),
            ])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name'),
            'search' => $request->string('q')->toString(),
            'roleFilter' => $request->string('role')->toString(),
            'access' => $request->string('access')->toString(),
            'status' => $request->string('status')->toString(),
            'presence' => $request->string('presence')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'latest',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => $this->stats(),
        ];

        // ajax-list.js asks for just the table + pagination so the toolbar
        // keeps its focus and values; the full page is returned otherwise.
        return $request->header('X-Fragment')
            ? view('admin.users._list', $data)
            : view('admin.users.index', $data);
    }

    /**
     * Headline figures for the stat cards at the top of the listing.
     *
     * Kept as simple counts on the unfiltered table so the cards read as a
     * stable overview, independent of whatever the on-screen filters do.
     */
    private function stats(): array
    {
        return [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'online' => User::where('last_seen_at', '>=', now()->subMinutes(UserSession::ONLINE_WINDOW))->count(),
            'sessions' => UserSession::whereNull('logout_at')->count(),
            'new' => User::where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    /**
     * Export the current filtered view as CSV.
     *
     * Uses the same query builder as index() so what downloads always
     * matches what is on screen, filters included.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('roles');
        $filename = 'users-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['ID', 'Name', 'Email', 'Roles', 'Direct permissions', 'Panel access', 'Created']);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $user) {
                    fputcsv($handle, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->roles->pluck('name')->implode(', '),
                        $user->permissions()->count(),
                        $user->is_admin ? 'Allowed' : 'Blocked',
                        $user->created_at?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Shared query for the listing and its export.
     */
    private function filtered(Request $request): Builder
    {
        $onlineSince = now()->subMinutes(UserSession::ONLINE_WINDOW);

        return User::query()
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                // Searching an IP is how an admin follows up on something
                // they saw in a login history, so it is folded in here
                // rather than given its own box.
                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "%{$term}%")
                    ->orWhereHas('sessions', fn ($s) => $s->where('ip_address', 'like', "%{$term}%")));
            })
            ->when($request->string('role')->toString(), function (Builder $query, string $role) {
                $query->whereHas('roles', fn ($q) => $q->where('name', $role));
            })
            ->when($request->string('access')->toString(), function (Builder $query, string $access) {
                $query->where('is_admin', $access === 'allowed');
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('presence')->toString(), function (Builder $query, string $presence) use ($onlineSince) {
                $presence === 'online'
                    ? $query->where('last_seen_at', '>=', $onlineSince)
                    : $query->where(fn (Builder $q) => $q
                        ->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $onlineSince));
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'oldest' => $query->oldest(),
                    'name_asc' => $query->orderBy('name'),
                    'name_desc' => $query->orderByDesc('name'),
                    'last_login' => $query->orderByRaw('last_login_at IS NULL, last_login_at DESC'),
                    'last_seen' => $query->orderByRaw('last_seen_at IS NULL, last_seen_at DESC'),
                    default => $query->latest(),
                };
            });
    }

    /**
     * Roles the signed-in user may hand out.
     *
     * Every role, except that only a Super Admin can make another one. Super
     * Admin bypasses every permission check on the platform (Gate::before),
     * so a restaurant owner able to tick it could make a member of staff -
     * or a second account of their own - the owner of every company's data.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        $canMakeSuperAdmins = (bool) request()->user()?->isSuperAdmin();

        return Role::orderBy('name')->get()
            ->reject(fn (Role $role) => $role->name === User::SUPER_ADMIN && ! $canMakeSuperAdmins)
            ->values();
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(),
            'roles' => $this->assignableRoles(),
            'tree' => PermissionRegistry::tree(),
            'assignedRoles' => [],
            'directPermissions' => [],
            'inherited' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'is_admin' => ['boolean'],
            'send_welcome' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles()->pluck('name')->all())],
        ]);

        /*
         | The plan's staff allowance (§21).
         |
         | After validation rather than before, so somebody who has both a
         | typo and a full plan is told about the typo first - being refused
         | for the limit, fixing the email and being refused again is a
         | worse way to learn the same thing.
         |
         | Counted against the tenant this account would join, which is the
         | one in context: User::booted stamps it the same way.
         */
        $tenantId = CurrentTenant::id() ?? CurrentTenant::soleId();

        if (! PlanAccess::canAddUser($tenantId)) {
            return back()
                ->withInput()
                ->with('error', PlanAccess::refusalFor($tenantId, 'user'));
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_admin' => (bool) ($data['is_admin'] ?? false),
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($data['roles'] ?? []);

            return $user;
        });

        ActivityLog::record('user.created', "Created user \"{$user->name}\"", $user, [
            'roles' => $user->roles->pluck('name')->all(),
        ]);

        $message = "User \"{$user->name}\" created.";
        $flash = 'status';

        if ($data['send_welcome'] ?? false) {
            $sent = WelcomeMailer::send($user);

            $message .= $sent
                ? " A welcome email is on its way to {$user->email}."
                : ' The welcome email could not be sent - check Settings > General > Mail Configuration.';

            // A failed email is worth noticing, but the account exists either
            // way, so it is a warning rather than an error.
            $flash = $sent ? 'status' : 'warning';
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with($flash, $message);
    }

    /**
     * Send the welcome email again, with a fresh link.
     *
     * Useful when the first one bounced, went to spam, or the link expired
     * before the person got to it.
     */
    public function resendWelcome(User $user): JsonResponse
    {
        if (! $user->is_active) {
            return ApiResponse::error(
                'This account is deactivated, so it cannot be signed into yet.',
                [],
                422,
            );
        }

        return WelcomeMailer::send($user)
            ? ApiResponse::success("Welcome email sent to {$user->email}.")
            : ApiResponse::error(
                'The email could not be sent. Check Settings > General > Mail Configuration.',
                [],
                502,
            );
    }

    public function edit(User $user): View
    {
        // Permissions the user gets purely from their roles, so the matrix
        // can show them as inherited rather than as direct grants.
        $inherited = $user->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        return view('admin.users.form', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
            'tree' => PermissionRegistry::tree(),
            'assignedRoles' => $user->roles->pluck('name')->all(),
            'directPermissions' => $user->getDirectPermissions()->pluck('name')->all(),
            'inherited' => $inherited,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_admin' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles()->pluck('name')->all())],
        ]);

        $this->guardSelfLockout($request, $user, $data['roles'] ?? []);

        DB::transaction(function () use ($user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_admin' => (bool) ($data['is_admin'] ?? false),
            ]);

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles($data['roles'] ?? []);
        });

        ActivityLog::record('user.updated', "Updated user \"{$user->name}\"", $user, [
            'roles' => $user->fresh()->roles->pluck('name')->all(),
        ]);

        return back()->with('status', "User \"{$user->name}\" updated.");
    }

    /**
     * Save this user's direct (user-wise) permission overrides via AJAX.
     *
     * These sit on top of whatever the assigned roles already grant.
     */
    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
            // See RoleController::sync() - the matrix submits a blank entry
            // that ConvertEmptyStringsToNull turns into null.
            'permissions.*' => ['nullable', 'string'],
        ]);

        $declared = PermissionRegistry::names();

        $permissions = collect($data['permissions'] ?? [])
            ->filter(fn ($name) => is_string($name) && $declared->contains($name))
            ->unique()
            ->values()
            ->all();

        $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();

        $user->syncPermissions($permissions);

        $after = $user->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all();

        ActivityLog::record('user.permissions_synced', "Updated direct permissions for \"{$user->name}\"", $user, [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]);

        return ApiResponse::success(
            sprintf('%d direct permission%s saved.', count($after), count($after) === 1 ? '' : 's'),
            ['total' => count($after)]
        );
    }

    /**
     * Delete several users at once from the listing's selection.
     *
     * Applies exactly the same guards as the single delete, and reports what
     * it refused rather than failing the whole batch - a silent partial
     * delete would be worse than a noisy one.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        // app.js fills a single hidden field with the ticked ids, comma joined.
        $data = $request->validate([
            'ids' => ['required', 'string'],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn (string $id) => (int) trim($id))
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return back()->with('error', 'No users were selected.');
        }

        $users = User::whereIn('id', $ids)->with('roles')->get();
        $superAdmins = User::role(User::SUPER_ADMIN)->count();

        $deleted = 0;
        $skipped = [];

        foreach ($users as $user) {
            if ($request->user()->is($user)) {
                $skipped[] = "{$user->name} (your own account)";

                continue;
            }

            if ($user->hasRole(User::SUPER_ADMIN) && $superAdmins <= 1) {
                $skipped[] = "{$user->name} (last Super Admin)";

                continue;
            }

            if ($user->hasRole(User::SUPER_ADMIN)) {
                $superAdmins--;
            }

            $user->delete();
            $deleted++;
        }

        if ($deleted > 0) {
            ActivityLog::record('user.bulk_deleted', "Deleted {$deleted} user(s)", null, [
                'skipped' => $skipped,
            ]);
        }

        $message = $deleted === 1 ? '1 user deleted.' : "{$deleted} users deleted.";

        return back()->with(
            $skipped ? 'warning' : 'status',
            $skipped ? $message.' Skipped: '.implode(', ', $skipped) : $message
        );
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole(User::SUPER_ADMIN) && User::role(User::SUPER_ADMIN)->count() <= 1) {
            return back()->with('error', 'The last Super Admin cannot be deleted.');
        }

        $name = $user->name;
        $user->delete();

        ActivityLog::record('user.deleted', "Deleted user \"{$name}\"");

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User \"{$name}\" deleted.");
    }

    /**
     * Stop an admin removing their own last route back in.
     *
     * Without this, an operator can drop their own Super Admin role and lose
     * access to the very screen needed to restore it.
     *
     * @param  array<int, string>  $roles
     */
    private function guardSelfLockout(Request $request, User $user, array $roles): void
    {
        if (! $request->user()->is($user)) {
            return;
        }

        if ($user->hasRole(User::SUPER_ADMIN) && ! in_array(User::SUPER_ADMIN, $roles, true)) {
            abort(403, 'You cannot remove the Super Admin role from your own account.');
        }
    }
}
