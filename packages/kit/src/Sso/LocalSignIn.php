<?php

namespace Khana\Kit\Sso;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a product does once Accounts has said who signed in: find its local
 * copy of that person, start its own session, and send them on. Bound by each
 * product; the sign-in routes only exist once it is.
 */
interface LocalSignIn
{
    public function signIn(SignedInPerson $person, Request $request): Response;

    public function failed(SignInFailed $reason, Request $request): Response;
}
