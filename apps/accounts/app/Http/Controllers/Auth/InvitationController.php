<?php

namespace App\Http\Controllers\Auth;

use App\Models\Invitation;
use App\Services\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepting an invitation: the person the owner added sets a password and is
 * in. Their name and access were set when they were invited, so this is the
 * one thing left to do.
 */
final class InvitationController
{
    public function show(string $token): View|Response
    {
        $invitation = Invitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return response()->view('auth.invitation-invalid', [], 410);
        }

        return view('auth.invitation', [
            'token' => $token,
            'user' => $invitation->user,
            'business' => $invitation->tenant,
        ]);
    }

    public function accept(Request $request, string $token, Team $team): RedirectResponse
    {
        $invitation = Invitation::findByToken($token);

        abort_if($invitation === null || ! $invitation->isUsable(), 410, 'This invitation has expired.');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $team->accept($invitation, $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'You are all set. Welcome to the team.');
    }
}
