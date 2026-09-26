<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One restaurant business: the legal entity whose GSTIN is on the bill.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property string|null $pan
 * @property string $status
 */
class Tenant extends Model
{
    use HasPublicId;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    protected $fillable = ['name', 'legal_name', 'gstin', 'pan', 'email', 'phone'];

    /** @return HasMany<Outlet, $this> */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * The data of accounts.tenant.upserted, and of the snapshot's `tenant`.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'status' => $this->status,
        ];
    }
}
