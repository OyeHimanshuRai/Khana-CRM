<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Turns a sign-up form into a working business.
 *
 * ---------------------------------------------------------------------------
 * All or nothing
 * ---------------------------------------------------------------------------
 *
 * A company with no owner, or an owner with no shop, is a support ticket that
 * nobody can reproduce. Everything here happens in one transaction, so a
 * failure part-way leaves no trace and the visitor simply tries again.
 *
 * ---------------------------------------------------------------------------
 * What the visitor does not get to choose
 * ---------------------------------------------------------------------------
 *
 * The role, the company the account belongs to and the subscription terms are
 * all decided here. The form supplies identity and a plan; it never supplies a
 * role, a tenant id or a price.
 *
 * ---------------------------------------------------------------------------
 * The trial is exactly the trial
 * ---------------------------------------------------------------------------
 *
 * The subscription is opened with no grace days. The platform's usual grace
 * window exists so a paying restaurant is not locked out mid-service over a
 * late invoice; handing it to somebody who never paid would quietly turn a
 * seven-day trial into ten.
 */
class TenantProvisioningService
{
    public const OWNER_ROLE = 'Tenant Owner';

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array{business_name: string, name: string, email: string, mobile: string, password: string}  $data
     *
     * @throws RuntimeException when the platform is not set up to take sign-ups
     */
    public function register(array $data, Plan $plan, string $period): User
    {
        return DB::transaction(function () use ($data, $plan, $period) {
            $role = Role::query()->where('name', self::OWNER_ROLE)->where('guard_name', 'web')->first();

            if ($role === null) {
                throw new RuntimeException('The "'.self::OWNER_ROLE.'" role does not exist. Run the role seeder before opening sign-ups.');
            }

            $name = trim($data['business_name']);
            $email = Str::lower(trim($data['email']));

            $tenant = Tenant::create([
                'name' => $name,
                'code' => $this->uniqueCode($name, 'tenants'),
                'slug' => Tenant::uniqueSlug($name),
                'email' => $email,
                'phone' => $data['mobile'],
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'pincode' => $data['pincode'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'logo_path' => $data['logo_path'] ?? null,
                'country' => 'India',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
                'sort_order' => 0,
            ]);

            $shop = Shop::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'code' => $this->uniqueCode($name, 'shops'),
                'slug' => Shop::uniqueSlug($name),
                'email' => $email,
                'phone' => $data['mobile'],
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'pincode' => $data['pincode'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'country' => 'India',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'invoice_prefix' => 'INV',
                'pos_prefix' => 'POS',
                'allow_negative_stock' => false,
                'block_expired_sale' => true,
                'recipe_deduction' => RecipeService::ON_READY,
                'requires_otp' => false,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            // Somewhere to put stock from the first minute, as the seeder
            // gives every branch.
            Warehouse::defaultFor($shop->id);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => trim($data['name']),
                'email' => $email,
                'mobile' => $data['mobile'],
                'password' => $data['password'],
                'is_admin' => true,
                'is_active' => true,
                'current_shop_id' => $shop->id,
            ]);

            // A business that signed itself up proves the address is its own - see User::needsEmailVerification.
            $user->forceFill(['all_shops_view' => false, 'must_verify_email' => true, 'email_verified_at' => null])->save();
            $user->shops()->attach($shop->id, ['is_default' => true]);
            $user->assignRole($role);

            $this->openTrial($tenant, $plan, $period);

            return $user->fresh();
        });
    }

    /**
     * The plan's own trial, starting now, with no grace on the end of it.
     *
     * The dates are subscribe()'s: a paid plan with no trial has already run
     * out and waits for its first payment; a free plan has no payment to wait
     * for and no end date.
     */
    private function openTrial(Tenant $tenant, Plan $plan, string $period): void
    {
        $trialDays = max(0, (int) $plan->trial_days);

        $subscription = $this->subscriptions->subscribe(
            $tenant, $plan, $period, $trialDays, null, 'Self-service sign-up',
        );

        $subscription->forceFill(['grace_days' => 0])->save();
    }

    /**
     * A short, readable, unused code: letters from the name, then a few
     * random characters so two "Spice Garden"s do not collide.
     *
     * Codes end up in invoice numbers, so they stay short and upper case. The
     * owner can rename the branch code later; the tenant code is internal.
     */
    private function uniqueCode(string $name, string $table): string
    {
        $letters = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)) ?: 'BIZ', 0, 5));

        do {
            $code = $letters.Str::upper(Str::random(4));
            $code = preg_replace('/[^A-Z0-9]/', 'X', $code);
        } while (DB::table($table)->where('code', $code)->exists());

        return $code;
    }
}
