<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorisesUserAccess;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    use AuthorisesUserAccess;

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
     * The tiles above the list, counting the same people the list shows.
     *
     * Counted through readable() rather than off the table: a "42 staff" tile
     * over a list of three is both a leak and a bug report.
     */
    private function stats(): array
    {
        $online = now()->subMinutes(UserSession::ONLINE_WINDOW);

        return [
            'total' => $this->readable()->count(),
            'active' => $this->readable()->where('is_active', true)->count(),
            'inactive' => $this->readable()->where('is_active', false)->count(),
            'online' => $this->readable()->where('last_seen_at', '>=', $online)->count(),
            'sessions' => UserSession::query()
                ->whereNull('logout_at')
                ->whereIn('user_id', $this->readable()->select('id'))
                ->count(),
            'new' => $this->readable()->where('created_at', '>=', now()->startOfMonth())->count(),
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
     * The accounts this reader may see at all (SS10).
     *
     * ---------------------------------------------------------------------
     * Whose staff
     * ---------------------------------------------------------------------
     *
     * `settings.users.view` answers "may they open this screen". It does not
     * answer "whose people are on it", and until this method existed nothing
     * did: a Tenant Owner opening Users saw every account on the platform,
     * including other restaurants' owners and their email addresses. Self-serve
     * signup turned that from a single-business install's harmless quirk into
     * a customer list handed to whoever signs up next.
     *
     * A Super Admin belongs to no company - `tenant_id` null - and is left out
     * for everybody except another Super Admin, who has every tenant in
     * `accessibleIds()` and gets the unfiltered query.
     */
    private function readable(): Builder
    {
        $query = User::query();

        if (auth()->user()?->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('tenant_id', CurrentTenant::accessibleIds() ?: [0]);
    }

    private function filtered(Request $request): Builder
    {
        $onlineSince = now()->subMinutes(UserSession::ONLINE_WINDOW);

        return $this->readable()
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

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(),
            'roles' => Role::orderBy('name')->get(),
            'tree' => PermissionRegistry::tree(),
            'assignedRoles' => [],
            'directPermissions' => [],
            'inherited' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'is_admin' => ['boolean'],
            'send_welcome' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
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
            $refusal = PlanAccess::refusalFor($tenantId, 'user');

            return $request->expectsJson()
                ? ApiResponse::error($refusal)
                : back()->withInput()->with('error', $refusal);
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

        // The envelope has no warning tone, so a failed welcome email rides in
        // on the success message rather than being lost with $flash.
        return $request->expectsJson()
            ? ApiResponse::success($message, [], route('admin.users.edit', $user))
            : redirect()->route('admin.users.edit', $user)->with($flash, $message);
    }

    /**
     * Send the welcome email again, with a fresh link.
     *
     * Useful when the first one bounced, went to spam, or the link expired
     * before the person got to it.
     */
    public function resendWelcome(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorise($user);

        if (! $user->is_active) {
            $refusal = 'This account is deactivated, so it cannot be signed into yet.';

            return $request->expectsJson()
                ? ApiResponse::error($refusal, [], 422)
                : back()->with('error', $refusal);
        }

        if (WelcomeMailer::send($user)) {
            $message = "Welcome email sent to {$user->email}.";

            return $request->expectsJson()
                ? ApiResponse::success($message)
                : back()->with('status', $message);
        }

        $failure = 'The email could not be sent. Check Settings > General > Mail Configuration.';

        return $request->expectsJson()
            ? ApiResponse::error($failure, [], 502)
            : back()->with('error', $failure);
    }

    public function edit(User $user): View
    {
        $this->authorise($user);

        // Permissions the user gets purely from their roles, so the matrix
        // can show them as inherited rather than as direct grants.
        $inherited = $user->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'tree' => PermissionRegistry::tree(),
            'assignedRoles' => $user->roles->pluck('name')->all(),
            'directPermissions' => $user->getDirectPermissions()->pluck('name')->all(),
            'inherited' => $inherited,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorise($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_admin' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
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

        $message = "User \"{$user->name}\" updated.";

        /*
         | The screen carries the redirect rather than staying put, because
         | four things on it are drawn from what this just changed: the
         | heading, the tab title, the count of permissions inherited from
         | roles, and the R markers down the direct-permissions matrix - which
         | is itself a form the reader may submit next. Save a role change over
         | fetch without this and the matrix underneath describes the roles the
         | account no longer has.
         */
        return $request->expectsJson()
            ? ApiResponse::success($message, [], route('admin.users.edit', $user))
            : back()->with('status', $message);
    }

    /**
     * Save this user's direct (user-wise) permission overrides via AJAX.
     *
     * These sit on top of whatever the assigned roles already grant.
     */
    public function syncPermissions(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorise($user);

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

        $message = sprintf('%d direct permission%s saved.', count($after), count($after) === 1 ? '' : 's');

        return $request->expectsJson()
            ? ApiResponse::success($message, ['total' => count($after)])
            : back()->with('status', $message);
    }

    /**
     * Delete several users at once from the listing's selection.
     *
     * Applies exactly the same guards as the single delete, and reports what
     * it refused rather than failing the whole batch - a silent partial
     * delete would be worse than a noisy one.
     */
    public function bulkDestroy(Request $request): RedirectResponse|JsonResponse
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
            return $request->expectsJson()
                ? ApiResponse::error('No users were selected.')
                : back()->with('error', 'No users were selected.');
        }

        $users = $this->readable()->whereIn('id', $ids)->with('roles')->get();
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

        if ($skipped) {
            $message .= ' Skipped: '.implode(', ', $skipped);
        }

        return $request->expectsJson()
            ? ApiResponse::success($message, ['deleted' => $deleted, 'skipped' => $skipped])
            : back()->with($skipped ? 'warning' : 'status', $message);
    }

    public function destroy(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorise($user);

        if ($request->user()->is($user)) {
            return $request->expectsJson()
                ? ApiResponse::error('You cannot delete your own account.')
                : back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole(User::SUPER_ADMIN) && User::role(User::SUPER_ADMIN)->count() <= 1) {
            return $request->expectsJson()
                ? ApiResponse::error('The last Super Admin cannot be deleted.')
                : back()->with('error', 'The last Super Admin cannot be deleted.');
        }

        $name = $user->name;
        $user->delete();

        ActivityLog::record('user.deleted', "Deleted user \"{$name}\"");

        $message = "User \"{$name}\" deleted.";

        return $request->expectsJson()
            ? ApiResponse::success($message)
            : redirect()->route('admin.users.index')->with('status', $message);
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
