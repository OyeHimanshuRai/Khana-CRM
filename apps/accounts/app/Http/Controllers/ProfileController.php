<?php

namespace App\Http\Controllers;

use App\Services\ReferenceEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A person's own details - the one profile they carry into every product.
 * Their name change reaches CRM and ERP; their password stays here, where
 * every product's sign-in checks it.
 */
final class ProfileController
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request, ReferenceEvents $events): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['nullable', 'string', 'max:16', Rule::unique('users', 'mobile')->ignore($user->id)],
        ]);

        abort_if($data['email'] === null && $data['mobile'] === null, 422, 'Keep at least an email or a mobile.');

        $user->update($data);
        $events->user($user->refresh());

        return back()->with('status', 'Profile saved.');
    }

    public function password(Request $request, ReferenceEvents $events): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($user->password !== null && ! Hash::check($data['current'], $user->password)) {
            throw ValidationException::withMessages(['current' => 'That is not your current password.']);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Changing a password signs the person out of both products.
        $events->sessionsRevoked($user, 'password_changed');

        return back()->with('status', 'Password changed.');
    }
}
