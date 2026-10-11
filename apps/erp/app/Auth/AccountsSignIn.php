<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Sso\LocalSignIn;
use Khana\Kit\Sso\SignedInPerson;
use Khana\Kit\Sso\SignInFailed;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Ui\OutletController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starting an ERP session for somebody Accounts has just signed in.
 *
 * Accounts says who the person is, which business they belong to, what role
 * they hold in ERP and which outlets they may open. All of it is copied onto
 * the local `users` row every time, so a change made in Accounts takes effect
 * at the next sign-in without anybody editing anything here.
 *
 * Refused, with the reason shown, rather than guessed at: a person with no
 * role in ERP, a role ERP has no rights for, or outlets ERP has not heard of
 * yet.
 */
final class AccountsSignIn implements LocalSignIn
{
    public function signIn(SignedInPerson $person, Request $request): Response
    {
        try {
            $user = DB::transaction(fn () => $this->mirror($person));
        } catch (SignInFailed $reason) {
            return $this->failed($reason, $request);
        }

        Auth::guard('web')->login($user);

        // A fresh session id, so a pre-sign-in session cannot be replayed.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Resolved (to nothing) while this request was still a guest.
        $context = app(Context::class);
        $context->forget();

        // "Open this outlet in ERP" from Accounts or CRM.
        OutletController::applyPending($request, $context);

        return redirect()->intended(route('home'));
    }

    public function failed(SignInFailed $reason, Request $request): Response
    {
        // To a page that explains and offers the button - not straight back
        // to Accounts, which has already signed this person in and would
        // bounce them here again.
        return redirect()->route('login')->with('error', $reason->getMessage());
    }

    /** @throws SignInFailed */
    private function mirror(SignedInPerson $person): User
    {
        if (! $person->canUse('erp')) {
            throw new SignInFailed('Your business has not given you access to ERP. Ask the owner to add you in Accounts.');
        }

        $role = $person->platformAdmin ? null : $person->roleIn('erp');

        // A preset, or a role this person's own business made in ERP.
        if (! $person->platformAdmin && ! Roles::exists($role, $person->tenantId)) {
            throw new SignInFailed(sprintf(
                'Your role in Accounts ("%s") has no rights set up in ERP. Ask the owner to check your role.',
                $role ?? 'none',
            ));
        }

        $outlets = $person->platformAdmin ? [] : $this->knownOutlets($person);

        $user = User::query()->firstOrNew(['ulid' => $person->userId]);

        if ($user->exists && ! $user->is_active) {
            throw new SignInFailed('This account has been switched off in ERP.');
        }

        $user->fill([
            'tenant_id' => $person->platformAdmin ? null : $person->tenantId,
            'name' => $person->name,
            'email' => $person->email,
            'mobile' => $person->mobile,
            'role_key' => $role,
            'is_platform_admin' => $person->platformAdmin,
            'outlet_ids' => $outlets,
        ])->save();

        return $user;
    }

    /**
     * The person's ERP outlets that this install has a reference copy of.
     *
     * An outlet reaches ERP by event from Accounts, a moment after it is
     * created there. Signing somebody in to an outlet ERP has not heard of
     * would show them an empty, broken-looking product, so that case is told
     * to wait instead.
     *
     * @return list<string>
     *
     * @throws SignInFailed
     */
    private function knownOutlets(SignedInPerson $person): array
    {
        if ($person->tenantId === null) {
            throw new SignInFailed('Accounts did not say which business you belong to.');
        }

        $given = $person->outletIdsIn('erp');

        if ($given === []) {
            throw new SignInFailed('You have not been given any outlet in ERP. Ask the owner to add one in Accounts.');
        }

        $known = DB::table('khana_outlets')
            ->whereIn('id', $given)
            ->where('tenant_id', $person->tenantId)
            ->orderBy('name')
            ->pluck('id')
            ->all();

        if ($known === []) {
            throw new SignInFailed('ERP is still setting up your outlet. Please try again in a minute.');
        }

        return $known;
    }
}
