<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Khana\Kit\Tenancy\Actor;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * One person. Signs in here, once, for every product.
 *
 * Belongs to one business, or to none for platform staff. Their role in each
 * product lives in product_access; `is_owner` is what lets them run the
 * business here: outlets, team and plan.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $password
 * @property string $status
 * @property bool $is_owner
 * @property bool $is_platform_admin
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property-read Tenant|null $tenant
 */
class User extends Authenticatable implements Actor, OAuthenticatable
{
    use HasApiTokens;
    use HasPublicId;
    use Notifiable;

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const DEACTIVATED = 'deactivated';

    protected $fillable = ['name', 'email', 'mobile', 'password'];

    // is_owner / is_platform_admin / status stay guarded - never set from a
    // request - so services set them with forceFill. These match the table's
    // defaults in memory, so an event fired in the same request as the insert
    // carries a real status, not null.
    protected $attributes = [
        'status' => self::ACTIVE,
        'is_owner' => false,
        'is_platform_admin' => false,
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_owner' => 'boolean',
            'is_platform_admin' => 'boolean',
            'prefers_all_outlets' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<ProductAccess, $this> */
    public function access(): HasMany
    {
        return $this->hasMany(ProductAccess::class);
    }

    public function accessTo(string $product): ?ProductAccess
    {
        return $this->access->firstWhere('product', $product);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isOwner(): bool
    {
        return $this->is_owner && $this->tenant_id !== null;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin && $this->tenant_id === null;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Owners and platform staff may turn on two-step sign-in. */
    public function mayUseTwoFactor(): bool
    {
        return $this->isOwner() || $this->isPlatformAdmin();
    }

    /** How this person is addressed in lists: email, else mobile. */
    public function contact(): string
    {
        return (string) ($this->email ?? $this->mobile ?? '');
    }

    /* -------------------------------------------------------------- Actor */

    /*
    | The tenancy layer (Kit\Tenancy\Context) reads a signed-in person
    | through this interface. In Accounts an owner reaches every outlet of
    | their own business; platform staff reach every business and every
    | outlet. A choice is remembered on the account, not the session.
    */

    public function tenantKey(): int|string|null
    {
        return $this->tenant_id;
    }

    /**
     * The outlets this person may reach. An owner reaches all of their
     * business's outlets; other staff, only those their access lists.
     *
     * @return list<int|string>
     */
    public function assignedOutletKeys(): array
    {
        if ($this->tenant_id === null) {
            return [];
        }

        if ($this->is_owner) {
            return Outlet::query()->where('tenant_id', $this->tenant_id)->orderBy('name')->pluck('id')->all();
        }

        return $this->access
            ->flatMap(fn (ProductAccess $access) => $access->reachableOutlets()->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    public function defaultOutletKey(): int|string|null
    {
        return $this->default_outlet_id;
    }

    public function rememberedTenantKey(): int|string|null
    {
        return $this->remembered_tenant_id;
    }

    public function rememberedOutletKey(): int|string|null
    {
        return $this->remembered_outlet_id;
    }

    public function prefersAllOutlets(): bool
    {
        return (bool) $this->prefers_all_outlets;
    }

    public function rememberContext(int|string|null $tenantKey, int|string|null $outletKey, bool $allOutlets): void
    {
        $this->forceFill([
            'remembered_tenant_id' => $tenantKey,
            'remembered_outlet_id' => $outletKey,
            'prefers_all_outlets' => $allOutlets,
        ])->save();
    }

    /**
     * The data of accounts.user.upserted.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'user_id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'status' => $this->status,
        ];
    }
}
