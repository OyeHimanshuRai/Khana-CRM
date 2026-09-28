<?php

namespace App\Auth;

use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Khana\Kit\Sso\LocalSignIn;
use Khana\Kit\Sso\SignedInPerson;
use Khana\Kit\Sso\SignInFailed;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * What CRM does once Accounts has said who signed in.
 *
 * Accounts owns the answers - who the person is, which business, which
 * outlets, which role - and CRM keeps rows that mirror them, because every
 * foreign key in this schema points at a local tenant, shop and user. So a
 * sign-in brings those rows up to date first and only then starts the
 * session:
 *
 *   tenant   found by Accounts' tenant id (tenants.ulid), created if new
 *   shops    one per outlet the person may use in CRM (shops.ulid)
 *   user     found by Accounts' user id (users.ulid); a local user with the
 *            same email and no Accounts id yet is adopted rather than
 *            duplicated, because the email is what Accounts verified
 *   role     the role here whose key matches Accounts' role_key - the rights
 *            live here, Accounts only says which one the person holds
 *
 * A platform admin gets Super Admin and no business. Anything that cannot be
 * matched refuses the sign-in with a reason rather than guessing: a person
 * with no role in CRM, a role CRM has no rights for, an email already held
 * in another business.
 */
final class AccountsSignIn implements LocalSignIn
{
    public function signIn(SignedInPerson $person, Request $request): Response
    {
        if (! $person->canUse('crm')) {
            return $this->failed(new SignInFailed('Your business has not given you access to CRM. Ask the owner to add you in Accounts.'), $request);
        }

        try {
            $user = DB::transaction(fn () => $this->mirror($person));
        } catch (SignInFailed $reason) {
            return $this->failed($reason, $request);
        }

        if (! $user->is_active) {
            return $this->failed(new SignInFailed('This account has been switched off in CRM.'), $request);
        }

        Auth::guard('web')->login($user);

        // A fresh session id, so a pre-sign-in session cannot be replayed.
        $request->session()->regenerate();

        UserSession::start($user, $request);
        LoginHistory::record(LoginHistory::SUCCESS, $user->email, $user, null, $request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => $user->login_count + 1,
        ])->saveQuietly();

        ActivityLog::record('auth.login', 'Signed in through Accounts', $user);

        // Resolved (to nothing) while this request was still a guest.
        CurrentTenant::forget();
        CurrentShop::forget();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function failed(SignInFailed $reason, Request $request): Response
    {
        LoginHistory::record(LoginHistory::FAILED, null, null, $reason->getMessage(), $request);

        // To the sign-in page, which offers the button and does not bounce
        // straight back to Accounts - a person Accounts has already signed in
        // would otherwise loop between the two.
        return redirect()->route('admin.login')->with('error', $reason->getMessage());
    }

    /**
     * Bring the local rows in line with what Accounts said.
     *
     * @throws SignInFailed
     */
    private function mirror(SignedInPerson $person): User
    {
        $tenant = $person->platformAdmin ? null : $this->tenant($person);
        $shops = $tenant ? $this->shops($person, $tenant) : collect();
        $user = $this->user($person, $tenant);
        $role = $this->role($person);

        $user->syncRoles([$role]);

        if ($tenant !== null) {
            $default = $user->current_shop_id && $shops->contains('id', $user->current_shop_id)
                ? $user->current_shop_id
                : $shops->first()?->id;

            $user->shops()->sync($shops->mapWithKeys(fn (Shop $shop) => [
                $shop->id => ['is_default' => $shop->id === $default],
            ])->all());

            $user->forceFill(['current_shop_id' => $default])->saveQuietly();
        }

        return $user;
    }

    /** @throws SignInFailed */
    private function tenant(SignedInPerson $person): Tenant
    {
        if ($person->tenantId === null) {
            throw new SignInFailed('Accounts did not say which business you belong to.');
        }

        $tenant = Tenant::withTrashed()->where('ulid', $person->tenantId)->first();

        if ($tenant?->trashed()) {
            throw new SignInFailed('This business has been closed in CRM.');
        }

        if ($tenant !== null) {
            return $tenant;
        }

        // The reference copy Accounts keeps current by event, when it has
        // arrived; otherwise a name good enough until it does.
        $reference = DB::table('khana_tenants')->where('id', $person->tenantId)->first();
        $name = $reference->name ?? ($person->outlets[0]['name'] ?? $person->name);

        $tenant = new Tenant([
            'name' => $name,
            'code' => $this->uniqueCode(Tenant::class, $name),
            'legal_name' => $reference->legal_name ?? null,
            'email' => $person->email,
            'phone' => $person->mobile,
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);

        $tenant->ulid = $person->tenantId;
        $tenant->slug = Tenant::uniqueSlug($name);
        $tenant->save();

        return $tenant;
    }

    /**
     * The outlets this person may use in CRM, as local shops.
     *
     * @return \Illuminate\Support\Collection<int, Shop>
     *
     * @throws SignInFailed
     */
    private function shops(SignedInPerson $person, Tenant $tenant): \Illuminate\Support\Collection
    {
        $allowed = $person->outletIdsIn('crm');

        $outlets = collect($person->outlets)
            ->filter(fn (array $outlet) => in_array($outlet['outlet_id'] ?? null, $allowed, true));

        if ($outlets->isEmpty()) {
            throw new SignInFailed('You have not been given any outlet in CRM. Ask the owner to add one in Accounts.');
        }

        return $outlets->map(function (array $outlet) use ($tenant) {
            $shop = Shop::withTrashed()->where('ulid', $outlet['outlet_id'])->first();

            if ($shop !== null) {
                if ((int) $shop->tenant_id !== (int) $tenant->id) {
                    throw new SignInFailed('Accounts put one of your outlets in a different business from the one CRM has it in.');
                }

                if ($shop->trashed()) {
                    $shop->restore();
                }

                return $shop;
            }

            $name = (string) $outlet['name'];
            $code = strtoupper((string) ($outlet['code'] ?? ''));

            $shop = new Shop([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'code' => $code !== '' && ! Shop::withTrashed()->where('code', $code)->exists()
                    ? $code
                    : $this->uniqueCode(Shop::class, $name),
                'country' => 'India',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
            ]);

            $shop->ulid = $outlet['outlet_id'];
            $shop->slug = Shop::uniqueSlug($name);
            $shop->save();

            return $shop;
        })->values();
    }

    /** @throws SignInFailed */
    private function user(SignedInPerson $person, ?Tenant $tenant): User
    {
        $user = User::query()->where('ulid', $person->userId)->first();

        // Adopt a local account Accounts has not been introduced to yet - the
        // email is the one thing both sides agree identifies a person.
        if ($user === null && $person->email !== null) {
            $user = User::query()->where('email', $person->email)->first();

            if ($user !== null && $tenant !== null && $user->tenant_id !== null && (int) $user->tenant_id !== (int) $tenant->id) {
                throw new SignInFailed('That email is already used by somebody in another business.');
            }
        }

        $user ??= new User([
            // Nobody signs in to CRM with a password any more; Accounts does
            // that. A random one keeps the column's NOT NULL honest.
            'password' => Str::password(40),
            'is_active' => true,
        ]);

        $user->forceFill([
            'ulid' => $person->userId,
            'tenant_id' => $tenant?->id,
            'name' => $person->name,
            // users.email is required here and optional in Accounts.
            'email' => $person->email ?? $person->userId.'@people.khana.invalid',
            'mobile' => $person->mobile ?? $user->mobile,
            // The back office is the product; who may do what in it is the role.
            'is_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /** @throws SignInFailed */
    private function role(SignedInPerson $person): Role
    {
        if ($person->platformAdmin) {
            return Role::findByName(User::SUPER_ADMIN, 'web');
        }

        $key = $person->roleIn('crm');

        $role = $key === null ? null : Role::query()
            ->where('key', $key)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            throw new SignInFailed(sprintf(
                'Your role in Accounts ("%s") has no rights set up in CRM. Ask the owner to check your role.',
                $key ?? 'none',
            ));
        }

        return $role;
    }

    /**
     * A short code from a name, unique across the table.
     *
     * Letters and digits, capitalised, eight characters, a number on the end
     * for the second "Sharma". Soft-deleted rows still hold their code.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueCode(string $model, string $name): string
    {
        $base = Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '')->upper()->limit(8, '')->toString() ?: 'SHOP';
        $code = $base;
        $suffix = 2;

        while ($model::query()->withTrashed()->where('code', $code)->exists()) {
            $tail = (string) $suffix++;
            $code = Str::limit($base, 20 - strlen($tail), '').$tail;
        }

        return $code;
    }
}
