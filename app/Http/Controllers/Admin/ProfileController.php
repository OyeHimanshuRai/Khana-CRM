<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * The signed-in user's own account screen.
 *
 * Deliberately ungated: this is the one place every admin may edit,
 * whatever their permissions, and it only ever touches $request->user().
 * Nothing here accepts a user id, so there is no way to reach another
 * account through it - role and access changes stay in UserController.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        // Permissions granted by the assigned roles, so the summary can
        // separate them from any direct grants an admin has added.
        $inherited = $user->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();

        $data = [
            'user' => $user,
            'roleNames' => $user->roles->pluck('name'),
            'inheritedCount' => $inherited->count(),
            'directCount' => $user->getDirectPermissions()->count(),
        ];

        // modal.js asks for the cards on their own; a plain visit still gets
        // the full page, which is also the no-JavaScript path.
        return $request->header('X-Fragment')
            ? view('admin.profile._form', $data)
            : view('admin.profile.edit', $data);
    }

    /**
     * Save name + email. Answers the JSON envelope app.js expects.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $changed = array_keys(array_diff_assoc($data, $user->only('name', 'email')));

        // A no-op submit should not write an audit row saying nothing changed.
        if ($changed === []) {
            return ApiResponse::success('No changes to save.', $this->identity($user));
        }

        $user->fill($data)->save();

        ActivityLog::record('profile.updated', 'Updated their own profile', $user, [
            'changed' => $changed,
        ]);

        return ApiResponse::success('Profile updated.', $this->identity($user));
    }

    /**
     * Change the password, proving ownership with the current one first.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            // `current_password` checks the value against the signed-in user's
            // stored hash, so a hijacked session cannot rotate the password.
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ], [
            'current_password.current_password' => 'That does not match your current password.',
            'password.different' => 'The new password must be different from your current one.',
        ]);

        $user->password = $request->string('password')->toString();
        $user->save();

        ActivityLog::record('profile.password_changed', 'Changed their own password', $user);

        return ApiResponse::success('Password changed.');
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
        // through this endpoint, never through the user form's validated data.
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
