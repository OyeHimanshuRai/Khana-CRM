<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Sso\RevokesSessions;

/**
 * "Sign out everywhere" and "this person has left", as told by Accounts.
 *
 * Accounts names the person by its own id; sessions here are filed under
 * ERP's local user row, so the id is translated first. A person ERP has
 * never seen has no session to end.
 */
final class EndSessions implements RevokesSessions
{
    public function revoke(string $userId, string $reason): void
    {
        $user = User::query()->where('ulid', $userId)->first();

        if ($user === null) {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        $user->forceFill(['remember_token' => null])->saveQuietly();

        if ($reason === 'deactivated') {
            $user->forceFill(['is_active' => false])->saveQuietly();
        }
    }
}
