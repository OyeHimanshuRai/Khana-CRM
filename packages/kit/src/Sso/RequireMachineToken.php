<?php

namespace Khana\Kit\Sso;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `machine:menu:read` - a route another service calls, guarded by a token
 * Accounts signed, carrying the scopes named. The verified token is left on
 * the request as `khana.machine` for the controller: a third-party sales key
 * names the one outlet it may write to, and the controller must use that,
 * never an outlet taken from the URL alone.
 */
final class RequireMachineToken
{
    public function __construct(private readonly MachineTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return response()->json(['message' => 'This request needs a token from Khana Accounts. Send it as: Authorization: Bearer <token>.'], 401);
        }

        try {
            $verified = $this->verifier->verify($token, $scopes);
        } catch (InsufficientScope $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidMachineToken $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $request->attributes->set('khana.machine', $verified);

        return $next($request);
    }
}
