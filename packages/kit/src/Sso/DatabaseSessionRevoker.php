<?php

namespace Khana\Kit\Sso;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The default: delete the person's rows from the database session table and
 * their Sanctum device tokens. The local user's key is the Accounts user id,
 * so both are found by it. A product that keeps sessions elsewhere binds its
 * own RevokesSessions.
 */
final class DatabaseSessionRevoker implements RevokesSessions
{
    public function revoke(string $userId, string $reason): void
    {
        $sessions = (string) config('session.table', 'sessions');

        $ended = Schema::hasTable($sessions)
            ? DB::table($sessions)->where('user_id', $userId)->delete()
            : 0;

        $tokens = Schema::hasTable('personal_access_tokens')
            ? DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete()
            : 0;

        Log::info('Sessions revoked by Accounts', [
            'user_id' => $userId,
            'reason' => $reason,
            'sessions' => $ended,
            'device_tokens' => $tokens,
        ]);
    }
}
