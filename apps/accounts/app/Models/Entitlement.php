<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What CRM and ERP were last told about one outlet and one product. Written
 * only by App\Services\Entitlements, which sends the change as it writes it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_id
 * @property string $product
 * @property string $state
 * @property string $plan
 * @property array<string, int|null> $limits
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property-read Outlet $outlet
 */
class Entitlement extends Model
{
    protected $fillable = ['tenant_id', 'outlet_id', 'product', 'state', 'plan', 'limits', 'valid_until', 'published_at'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'valid_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->state, [Subscription::TRIAL, Subscription::ACTIVE, Subscription::GRACE], true);
    }

    public function isHeld(): bool
    {
        return $this->isUsable() || $this->state === Subscription::SUSPENDED;
    }

    /**
     * The data of accounts.entitlement.changed.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'product' => $this->product,
            'state' => $this->state,
            'plan' => $this->plan,
            'limits' => (object) ($this->limits ?? []),
            'valid_until' => $this->valid_until?->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
