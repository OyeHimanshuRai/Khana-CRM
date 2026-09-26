<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorisesUserAccess;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BlockedIp;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Support\ApiResponse;
use App\Support\IpLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The security side of a user: sessions, login history, IP blocks and the
 * account on/off switch.
 *
 * Kept apart from UserController, which owns the account's own details -
 * these actions answer "where is this person signed in and should they
 * still be", not "what is their name".
 */
class UserSecurityController extends Controller
{
    use AuthorisesUserAccess;

    /** How many rows of history the detail screen shows. */
    private const HISTORY_LIMIT = 50;

    /**
     * The full security picture for one user.
     *
     * Answers a fragment for the modal and a full page otherwise, the same
     * contract ajax-list.js and modal.js already use.
     */
    public function show(Request $request, User $user): View
    {
        $this->authorise($user);

        $currentSessionId = $request->session()->getId();

        $sessions = $user->sessions()->limit(self::HISTORY_LIMIT)->get();
        $history = $user->loginHistories()->limit(self::HISTORY_LIMIT)->get();
        $ipHistory = $user->ipHistory();

        // Resolved here rather than at login: geolocation is a third-party
        // call, and nothing about signing in should wait on one. Cached for
        // a month, so opening this screen repeatedly costs nothing.
        $locations = IpLocator::many(
            $sessions->pluck('ip_address')
                ->merge($history->pluck('ip_address'))
                ->merge($ipHistory->pluck('ip_address'))
        );

        $this->backfillSessionLocations($sessions, $locations);

        $data = [
            'user' => $user->load('roles'),
            'currentSessionId' => $currentSessionId,
            'sessions' => $sessions,
            'activeCount' => $sessions->filter->isActive()->count(),
            'history' => $history,
            'failedCount' => $user->loginHistories()->failed()->count(),
            'ipHistory' => $ipHistory,
            'locations' => $locations,
            'blocks' => BlockedIp::query()
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))
                ->latest()
                ->get(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.users._security', $data)
            : view('admin.users.security', $data);
    }

    /* ------------------------------------------------------ account status */

    /**
     * Flip the account between active and deactivated.
     */
    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $this->authorise($user);

        if ($request->user()->is($user)) {
            return ApiResponse::error('You cannot deactivate your own account.', [], 422);
        }

        if ($user->hasRole(User::SUPER_ADMIN) && ! $request->user()->hasRole(User::SUPER_ADMIN)) {
            return ApiResponse::error('Only a Super Admin can change another Super Admin.', [], 403);
        }

        $active = ! $user->is_active;

        $user->forceFill(['is_active' => $active])->save();

        // Deactivating has to bite now, not at the next sign-in: an open
        // session would otherwise keep working indefinitely.
        $ended = $active ? 0 : $user->terminateSessions();

        ActivityLog::record(
            $active ? 'user.activated' : 'user.deactivated',
            ($active ? 'Activated' : 'Deactivated')." user \"{$user->name}\"",
            $user,
            ['sessions_ended' => $ended],
        );

        $message = $active
            ? "\"{$user->name}\" can sign in again."
            : "\"{$user->name}\" has been deactivated."
                .($ended > 0 ? " {$ended} active session".($ended === 1 ? '' : 's').' ended.' : '');

        return ApiResponse::success($message, [
            'is_active' => $active,
            'sessions_ended' => $ended,
        ]);
    }

    /* ------------------------------------------------------------ sessions */

    /**
     * End one session on one device.
     */
    public function destroySession(Request $request, User $user, UserSession $session): JsonResponse
    {
        $this->authorise($user);

        abort_unless($session->user_id === $user->id, 404);

        if ($session->isCurrent($request->session()->getId())) {
            return ApiResponse::error('That is the session you are using right now.', [], 422);
        }

        if (! $session->isActive()) {
            return ApiResponse::success('That session had already ended.', ['active' => $this->activeCount($user)]);
        }

        $session->terminate('admin');

        ActivityLog::record(
            'user.session_ended',
            "Ended a session for \"{$user->name}\"",
            $user,
            ['device' => $session->describeDevice(), 'ip' => $session->ip_address],
        );

        return ApiResponse::success('Session signed out.', ['active' => $this->activeCount($user)]);
    }

    /**
     * End every session for this user.
     */
    public function destroyAllSessions(Request $request, User $user): JsonResponse
    {
        $this->authorise($user);

        // Signing yourself out of the screen you are working on is almost
        // never the intent, so the current device is spared by default.
        $keepCurrent = $request->boolean('keep_current', true) && $request->user()->is($user);

        $ended = $user->terminateSessions($keepCurrent ? $request->session()->getId() : null);

        if ($ended === 0) {
            return ApiResponse::success('There were no active sessions.', ['active' => 0]);
        }

        ActivityLog::record(
            'user.sessions_ended',
            "Signed \"{$user->name}\" out of {$ended} device".($ended === 1 ? '' : 's'),
            $user,
            ['kept_current' => $keepCurrent],
        );

        return ApiResponse::success(
            "Signed out of {$ended} device".($ended === 1 ? '' : 's').'.',
            ['active' => $this->activeCount($user)],
        );
    }

    /* ---------------------------------------------------------- ip blocks */

    /**
     * Block one or more addresses.
     */
    public function blockIp(Request $request, User $user): JsonResponse
    {
        $this->authorise($user);

        $data = $request->validate([
            // The form submits a comma or newline separated list, so several
            // addresses from the IP history can be blocked in one go.
            'ip_addresses' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', 'in:user,global'],
            'duration' => ['required', 'in:permanent,1h,24h,7d,30d'],
        ]);

        $addresses = collect(preg_split('/[\s,]+/', $data['ip_addresses']))
            ->map(fn (string $ip) => trim($ip))
            ->filter()
            ->unique()
            ->values();

        $invalid = $addresses->reject(fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false);

        if ($invalid->isNotEmpty()) {
            return ApiResponse::error('Not a valid IP address: '.$invalid->implode(', '), [
                'ip_addresses' => ['Check the highlighted addresses.'],
            ]);
        }

        $expiresAt = match ($data['duration']) {
            '1h' => now()->addHour(),
            '24h' => now()->addDay(),
            '7d' => now()->addDays(7),
            '30d' => now()->addDays(30),
            default => null,
        };

        $actor = $request->user();

        /*
         | A global block has no user_id, so `ip.allowed` turns every request
         | from that address away - including the other restaurants on the
         | install, who never agreed to it. Choosing the platform is ours;
         | an owner blocks an address from their own staff account.
         */
        $scopedToUser = $data['scope'] === 'user' || ! $actor?->isSuperAdmin();

        foreach ($addresses as $ip) {
            // Re-blocking an address that is already blocked at this scope
            // should refresh it, not stack duplicate rows.
            BlockedIp::updateOrCreate(
                [
                    'ip_address' => $ip,
                    'user_id' => $scopedToUser ? $user->id : null,
                    'status' => BlockedIp::ACTIVE,
                ],
                [
                    'reason' => $data['reason'] ?? null,
                    'blocked_by' => $actor->id,
                    'blocked_by_name' => $actor->name,
                    'blocked_at' => now(),
                    'expires_at' => $expiresAt,
                    'lifted_at' => null,
                ],
            );
        }

        ActivityLog::record(
            'user.ip_blocked',
            'Blocked '.$addresses->count().' IP address'.($addresses->count() === 1 ? '' : 'es')
                .($scopedToUser ? " for \"{$user->name}\"" : ' for all accounts'),
            $user,
            [
                'ips' => $addresses->all(),
                'scope' => $data['scope'],
                'expires_at' => $expiresAt?->toDateTimeString(),
                'reason' => $data['reason'] ?? null,
            ],
        );

        return ApiResponse::success(
            $addresses->count().' IP address'.($addresses->count() === 1 ? '' : 'es').' blocked.',
        );
    }

    /**
     * Lift one block.
     */
    public function unblockIp(Request $request, User $user, BlockedIp $block): JsonResponse
    {
        $this->authorise($user);

        // {block} is bound by id as well, and nothing in the path scopes the
        // lift to the {user} it was reached through. A platform-wide block
        // carries no user_id: it is ours to take off, not a customer's.
        abort_unless(
            $block->user_id === $user->id
                || ($block->user_id === null && $request->user()?->isSuperAdmin()),
            404,
        );

        $block->lift();

        ActivityLog::record(
            'user.ip_unblocked',
            "Unblocked IP {$block->ip_address}",
            $user,
            ['ip' => $block->ip_address, 'scope' => $block->user_id === null ? 'global' : 'user'],
        );

        return ApiResponse::success("{$block->ip_address} unblocked.");
    }

    private function activeCount(User $user): int
    {
        return $user->sessions()->whereNull('logout_at')->count();
    }

    /**
     * Store a freshly resolved location on the session row.
     *
     * Written once so the value survives the cache expiring, and so an
     * export or a later read does not need the third party again.
     *
     * @param  \Illuminate\Support\Collection<int, UserSession>  $sessions
     * @param  array<string, string|null>  $locations
     */
    private function backfillSessionLocations($sessions, array $locations): void
    {
        foreach ($sessions as $session) {
            $resolved = $locations[$session->ip_address] ?? null;

            if (blank($resolved) || filled($session->location)) {
                continue;
            }

            $session->forceFill(['location' => $resolved])->saveQuietly();
        }
    }
}
