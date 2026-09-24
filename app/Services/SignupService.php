<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A restaurant signing itself up (§21).
 *
 * ---------------------------------------------------------------------------
 * What an account actually is
 * ---------------------------------------------------------------------------
 *
 * Four rows, and every one of them is needed before anybody can bill a single
 * plate:
 *
 *   tenant        the business. What a subscription is sold to and what every
 *                 other row hangs off.
 *   shop          its first outlet. The thing the plan is priced per, the
 *                 thing an invoice names, the thing a QR sticker points at.
 *   user          the owner's own sign-in, holding Tenant Owner so they can
 *                 add their staff without asking anybody.
 *   subscription  which plan that outlet is on, and until when.
 *
 * Until this class existed, all four were an administrator's job - which meant
 * a restaurant could read the pricing at eleven at night and then wait until
 * somebody opened the back office. That is the whole reason for this file.
 *
 * ---------------------------------------------------------------------------
 * It is one transaction on purpose
 * ---------------------------------------------------------------------------
 *
 * A half-made account is worse than none: a tenant with no outlet cannot be
 * subscribed, an outlet with no owner is invisible to the person who just paid
 * for it, and both look exactly like a working account on a list screen. So
 * either all four rows land or the visitor sees the form again.
 *
 * ---------------------------------------------------------------------------
 * What it deliberately does NOT do
 * ---------------------------------------------------------------------------
 *
 * It does not sign anybody in - that is the controller's job, and it is a
 * session concern rather than a data one. It does not take money either: a
 * plan with a trial does not need any, and a plan without one is handed
 * straight to billing with a term that has already ended. See `register`.
 */
class SignupService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CatalogStarter $starter,
    ) {}

    /**
     * Create a business, its first outlet, its owner and its subscription.
     *
     * @param  array<string, mixed>  $data  validated input from SignupController
     * @return array{tenant: Tenant, shop: Shop, owner: User, subscription: Subscription}
     */
    public function register(array $data, Plan $plan, string $period = Plan::MONTHLY): array
    {
        $created = DB::transaction(function () use ($data, $plan, $period) {
            $tenant = $this->tenant($data);
            $shop = $this->shop($tenant, $data);
            $owner = $this->owner($tenant, $shop, $data);

            /*
             | The vocabulary the software speaks, and somewhere to put stock.
             |
             | Units and GST slabs used to be seeded once per install and
             | shared by everybody; now that the catalogue belongs to a
             | company, a new business starts with none - and a restaurant
             | whose first act is being told it cannot save a dish without
             | first inventing "kilogram" has been handed a chore, not a
             | product. See App\Services\CatalogStarter.
             */
            $this->starter->forTenant($tenant);
            $this->starter->forShop($shop);

            $subscription = $this->subscriptions->subscribeShop(
                shop: $shop,
                plan: $plan,
                period: $period,
                note: 'Signed up online',
            );

            /*
             | A plan with no trial is not free for a fortnight, and the row
             | this service has just written would otherwise say it is.
             |
             | SubscriptionService leaves `ends_at` null when there is no
             | trial and no earlier term to carry over, and null means
             | perpetual - the right answer for an in-house account, and
             | exactly the wrong one for somebody who signed themselves up
             | ninety seconds ago. So the term is closed immediately and the
             | grace window is set to nothing: the outlet is real, its owner
             | can sign in, and the only screen they can reach is the one
             | that takes their money.
             |
             | Nothing is hidden from them. EnsureShopIsSubscribed names the
             | outlet and sends them to billing, which is reachable precisely
             | because it sits outside that gate.
             */
            if ($subscription->trial_ends_at === null && $subscription->ends_at === null) {
                $subscription->forceFill([
                    'ends_at' => Carbon::now(),
                    'grace_days' => 0,
                ])->save();

                PlanAccess::forget();
            }

            return [
                'tenant' => $tenant,
                'shop' => $shop,
                'owner' => $owner,
                'subscription' => $subscription->refresh(),
            ];
        });

        /*
         | Both are memoised per request, and both were resolved while this
         | request was still a guest with no company and no branch. Dropping
         | them here means the first authenticated page reads the account
         | that now exists rather than the empty answer from before it did.
         */
        CurrentTenant::forget();
        CurrentShop::forget();

        return $created;
    }

    /* ------------------------------------------------------------- rows */

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenant(array $data): Tenant
    {
        $name = trim((string) $data['business_name']);

        $tenant = new Tenant([
            'name' => $name,
            'code' => $this->uniqueCode(Tenant::class, $name),
            'phone' => $data['mobile'] ?? null,
            'email' => $data['email'],
            'city' => $data['city'] ?? null,
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);

        $tenant->slug = Tenant::uniqueSlug($name);
        $tenant->save();

        return $tenant;
    }

    /**
     * The first outlet.
     *
     * Its name defaults to the business's own, because a single restaurant is
     * the common case and asking somebody to type "Sharma Dhaba" twice is a
     * field that only ever collects typos. A group adds its other branches
     * from Settings once it is inside.
     *
     * `modules` is left null on purpose. Null means "every line of business",
     * which Modules::forShop then caps by whatever the plan grants - so the
     * plan is the ceiling and the outlet never has to be told twice what it
     * bought. See App\Support\Modules::forShop.
     *
     * @param  array<string, mixed>  $data
     */
    private function shop(Tenant $tenant, array $data): Shop
    {
        $name = trim((string) ($data['outlet_name'] ?? '')) ?: trim((string) $data['business_name']);

        $shop = new Shop([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $this->uniqueCode(Shop::class, $name),
            'phone' => $data['mobile'] ?? null,
            'email' => $data['email'],
            'city' => $data['city'] ?? null,
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);

        $shop->slug = Shop::uniqueSlug($name);
        $shop->save();

        return $shop;
    }

    /**
     * The owner's own sign-in.
     *
     * `is_admin` because the back office is the product - an owner who could
     * not open it would have bought a QR menu and nothing else. Tenant Owner
     * because everything inside their company is theirs to run, and nothing
     * outside it is: the role grants no platform settings, no other business
     * and no plan editing. See RolePermissionSeeder.
     *
     * The email is marked verified. Nothing in this system gates on that
     * column today, and leaving it null would leave a flag that reads as "we
     * are waiting on them" when nobody is.
     *
     * @param  array<string, mixed>  $data
     */
    private function owner(Tenant $tenant, Shop $shop, array $data): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => trim((string) $data['name']),
            'email' => $data['email'],
            'mobile' => $data['mobile'] ?? null,
            'password' => $data['password'],
            'is_admin' => true,
            'is_active' => true,
            'current_shop_id' => $shop->id,
        ]);

        $user->forceFill(['email_verified_at' => Carbon::now()])->save();

        $user->assignRole(User::TENANT_OWNER);

        // The pivot is the authorisation; current_shop_id above is only where
        // the switcher opens. Without this row the owner may not read their
        // own outlet - see App\Support\CurrentShop.
        $user->shops()->attach($shop->id, ['is_default' => true]);

        return $user;
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * A short code nobody has taken.
     *
     * Every one of these tables carries a unique `code`, and an administrator
     * types theirs. A visitor is never going to be asked to invent one, so it
     * comes off the name: letters and digits only, capitalised, eight
     * characters, and a number on the end for the second "Sharma".
     *
     * Soft-deleted rows still hold their code against the unique index, so
     * they count as taken - the same rule HasUniqueSlug follows and for the
     * same reason.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueCode(string $model, string $name): string
    {
        $base = Str::of($name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '')
            ->upper()
            ->limit(8, '')
            ->toString();

        if ($base === '') {
            $base = 'SHOP';
        }

        $code = $base;
        $suffix = 2;

        while ($model::query()->withTrashed()->where('code', $code)->exists()) {
            $tail = (string) $suffix++;
            $code = Str::limit($base, 20 - strlen($tail), '').$tail;
        }

        return $code;
    }
}
