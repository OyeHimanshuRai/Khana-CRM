<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The back office: plans, every business, sign-in history, blocked
 * addresses. Platform staff only, who belong to no business.
 */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'This is the platform back office.');

        return $next($request);
    }
}
