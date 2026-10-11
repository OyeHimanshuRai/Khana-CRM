<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner area: outlets, team and plan. Only the person who runs the
 * business here may reach it - staff sign in for a product, not for this.
 */
final class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isOwner(), 403, 'Only the business owner can manage this.');

        return $next($request);
    }
}
