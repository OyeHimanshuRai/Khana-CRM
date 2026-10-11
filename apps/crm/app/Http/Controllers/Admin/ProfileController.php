<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Accounts;
use App\Support\ApiResponse;
use App\Support\IpLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * The signed-in user's own account screen.
 *
 * Deliberately ungated: this is the one place every admin may edit,
 * whatever their permissions, and it only ever touches $request->user().
 * Nothing here accepts a user id, so there is no way to reach another
 * account through it.
 *
 * ---------------------------------------------------------------------------
 * Only what is CRM's own
 * ---------------------------------------------------------------------------
 *
 * A person's name, email and password are Khana Accounts' - it is where they
 * sign in, and App\Auth\AccountsSignIn copies the name and email down at
 * every sign-in, so an edit here would be quietly undone the next morning.
 * The screen shows them and links there.
 *
 * What stays is what only CRM knows about:
 *
 *   picture    the avatar in CRM's own header
 *   devices    where this person is signed in to CRM, with a way to sign
 *              one out - a till left logged in at the other branch, a phone
 *              that was lost. Signing someone *else* out, or switching an
 *              account off, is the owner's job in Accounts, which tells CRM
 *              through accounts.sessions.revoked.
 */
class ProfileController extends Controller
{
    /** How many sessions the devices card lists. */
    private const SESSION_LIMIT = 20;

    public function edit(Request $request): View
    {
        $user = $request->user();

        // Permissions granted by the assigned roles, so the summary can
        // separate them from any direct grants an admin has added.
        $inherited = $user->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();

        $sessions = $this->sessions($user);

        $data = [
            'user' => $user,
            'roleNames' => $user->roles->pluck('name'),
            'inheritedCount' => $inherited->count(),
            'directCount' => $user->getDirectPermissions()->count(),
            'sessions' => $sessions,
            'currentSessionId' => $request->session()->getId(),
            // Resolved here rather than at sign-in: geolocation is a
            // third-party call, and nothing about signing in should wait on
            // one. Cached, so opening this screen again costs nothing.
            'locations' => IpLocator::many($sessions->pluck('ip_address')),
            'accountsProfile' => Accounts::url('/profile'),
        ];

        // modal.js asks for the cards on their own; a plain visit still gets
        // the full page, which is also the no-JavaScript path.
        return $request->header('X-Fragment')
            ? view('admin.profile._form', $data)
            : view('admin.profile.edit', $data);
    }

    /**
     * Replace the profile picture.
     *
     * Stored on the `public` disk, which is exposed through the
     * public/storage symlink (`php artisan storage:link`).
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.image' => 'Please choose an image file.',
            'avatar.mimes' => 'Use a JPG, PNG or WebP image.',
            'avatar.max' => 'The picture must be 2 MB or smaller.',
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        $path = $request->file('avatar')->store('avatars', 'public');

        // Not mass-assignable on purpose - the only way to set this column is
        // through this endpoint.
        $user->forceFill(['avatar_path' => $path])->save();

        // Deleted only once the new path is committed, so a failed write can
        // never leave the account pointing at a file that no longer exists.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        ActivityLog::record('profile.avatar_updated', 'Updated their profile picture', $user);

        return ApiResponse::success('Picture updated.', $this->identity($user));
    }

    /**
     * Drop the picture and fall back to the initials placeholder.
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $path = $user->avatar_path;

        if (blank($path)) {
            return ApiResponse::success('There was no picture to remove.', $this->identity($user));
        }

        $user->forceFill(['avatar_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('profile.avatar_removed', 'Removed their profile picture', $user);

        return ApiResponse::success('Picture removed.', $this->identity($user));
    }

    /* ------------------------------------------------------------ devices */

    /**
     * Sign one of your own devices out of CRM.
     *
     * {session} is bound by id alone, so the owner check is what keeps this
     * to your own devices - someone else's session is a 404, not a refusal
     * that confirms it exists.
     */
    public function destroySession(Request $request, UserSession $session): JsonResponse
    {
        $user = $request->user();

        abort_unless((int) $session->user_id === (int) $user->id, 404);

        if ($session->isCurrent($request->session()->getId())) {
            return ApiResponse::error('That is the device you are using. Sign out from the menu instead.', [], 422);
        }

        if (! $session->isActive()) {
            return ApiResponse::success('That device was already signed out.');
        }

        $session->terminate('user');

        ActivityLog::record('profile.session_ended', 'Signed one of their devices out', $user, [
            'device' => $session->describeDevice(),
            'ip' => $session->ip_address,
        ]);

        return ApiResponse::success('Device signed out.');
    }

    /**
     * Sign every other device out of CRM, keeping this one.
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $request->session()->getId();

        $others = $user->sessions()
            ->whereNull('logout_at')
            ->where('session_id', '!=', $current)
            ->get();

        foreach ($others as $session) {
            $session->terminate('user');
        }

        if ($others->isEmpty()) {
            return ApiResponse::success('No other device was signed in.');
        }

        ActivityLog::record('profile.sessions_ended', sprintf(
            'Signed %d other device%s out',
            $others->count(),
            $others->count() === 1 ? '' : 's',
        ), $user);

        return ApiResponse::success(sprintf(
            'Signed out of %d other device%s.',
            $others->count(),
            $others->count() === 1 ? '' : 's',
        ));
    }

    /**
     * Still-open sessions first, then the most recent closed ones - what
     * somebody checking "am I still signed in on that till?" wants to see.
     *
     * @return Collection<int, UserSession>
     */
    private function sessions(User $user): Collection
    {
        return $user->sessions()
            ->reorder()
            ->orderByRaw('CASE WHEN logout_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('last_activity_at')
            ->limit(self::SESSION_LIMIT)
            ->get();
    }

    /**
     * The bits of the account the header renders, returned on every save so
     * the chrome can refresh itself without a reload.
     *
     * @return array<string, string|null>
     */
    private function identity(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials(),
            'avatar' => $user->avatarUrl(),
        ];
    }
}
