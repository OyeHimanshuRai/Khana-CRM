<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One restaurant or cafe of a business. What a plan is bought for, what a
 * bill names, and what every row in CRM and ERP belongs to.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string $timezone
 * @property string $day_cutoff
 * @property-read Tenant $tenant
 */
class Outlet extends Model
{
    use HasPublicId;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const SUSPENDED = 'suspended';

    /** GST state codes, for the outlet form. */
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    protected $fillable = [
        'name', 'code', 'gstin', 'fssai',
        'address_line1', 'address_line2', 'city', 'pincode', 'state_code', 'phone',
        'timezone', 'day_cutoff',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Entitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function stateName(): string
    {
        return self::STATES[$this->state_code] ?? $this->state_code;
    }

    public function addressLine(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, $this->pincode])->filter()->implode(', ');
    }

    /**
     * The data of accounts.outlet.upserted, and of each snapshot outlet.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'gstin' => $this->gstin,
            'fssai' => $this->fssai,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'pincode' => $this->pincode,
            ],
            'state_code' => $this->state_code,
            'timezone' => $this->timezone,
            'day_cutoff' => $this->day_cutoff,
            'status' => $this->status,
        ];
    }
}
