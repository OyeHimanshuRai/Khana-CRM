<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the online/offline picture honest.
 *
 * Runs after the response is built, so a slow write never delays what the
 * user sees. Both timestamps are throttled: presence is only interesting to
 * the minute, and writing on literally every request would put two updates
 * on the hot path of every page load and asset-less fetch.
 */
class TrackUserSession
{
    /** Seconds between writes for one session. */
    private const THROTTLE = 60;

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Called by the framework once the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $sessionId = $request->session()->getId();

        $session = UserSession::query()
            ->where('session_id', $sessionId)
            ->whereNull('logout_at')
            ->first();

        // No row means the sign-in predates this module, or an admin has
        // just closed the session out from under it. Adopt the former;
        // leave the latter alone so a remote logout is not undone.
        if (! $session) {
            if (UserSession::where('session_id', $sessionId)->exists()) {
                return;
            }

            $session = UserSession::start($user, $request);
        }

        $now = now();

        if ($session->last_activity_at?->diffInSeconds($now) < self::THROTTLE) {
            return;
        }

        $session->forceFill(['last_activity_at' => $now])->save();

        $user->forceFill(['last_seen_at' => $now])->saveQuietly();
    }
}
