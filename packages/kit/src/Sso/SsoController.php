<?php

namespace Khana\Kit\Sso;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Ui\OutletController;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /sso/login and GET /sso/callback.
 */
final class SsoController
{
    public function login(Request $request, SignInClient $client, LocalSignIn $local): RedirectResponse|Response
    {
        try {
            return $client->redirect($request);
        } catch (SignInFailed $e) {
            return $local->failed($e, $request);
        }
    }

    public function callback(Request $request, SignInClient $client, LocalSignIn $local): Response
    {
        try {
            $person = $client->callback($request);
        } catch (SignInFailed $e) {
            return $local->failed($e, $request);
        }

        $request->session()->regenerate();

        $response = $local->signIn($person, $request);

        // The outlet the product switcher was opening, if sign-in interrupted it.
        OutletController::applyPending($request, app(Context::class));

        return $response;
    }
}
