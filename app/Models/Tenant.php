<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One restaurant business on the platform.
 *
 * A tenant owns branches (shops), and through them everything else. It is
 * the legal entity: the GSTIN on the invoice, the name above the door, the
 * logo on the paperwork. A branch overrides only what genuinely differs -
 * its own address, its own invoice series.
 *
 * Deliberately not scoped by CurrentShop: this is the table that *defines*
 * the scope, so scoping it by itself would be circular. Who may see which
 * tenant is decided in App\Support\CurrentTenant.
 */
class Tenant extends Model
{
    use HasUniqueSlug;
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'slug', 'legal_name',
        'gstin', 'pan',
        'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pincode', 'country',
        'logo_path', 'latitude', 'longitude',
        'currency', 'timezone',
        'is_active', 'sort_order',
    ];

    // `license_exempt` is written by the platform alone, never by a form.

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'license_exempt' => 'boolean',
            'suspended_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return HasMany<Shop, $this> */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Every subscription this business has ever held, oldest first.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one that counts.
     *
     * The latest row wins, because changing plan writes a new one and
     * renewing does not. Deliberately not filtered by state: an expired
     * subscription is still this tenant's subscription, and it is the row
     * that has to be on screen for anybody to see why the account stopped
     * working.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** Every license this business has held or been given, newest first. */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class)->orderByDesc('id');
    }

    /** The company's first user - the owner who signed up - for the contact line in the Companies list. */
    public function owner(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class)->oldestOfMany();
    }

    /** The newest license on file for this business, whatever its state - what the Companies list shows. */
    public function latestLicense(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(License::class)->latestOfMany();
    }

    /**
     * The license the business holds right now (pending, active, suspended or
     * blocked) - at most one, enforced by `licenses.holder_tenant_id`.
     */
    public function heldLicense(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(License::class, 'holder_tenant_id');
    }

    /** Started with the free trial (chosen at registration). */
    public function startedOnTrial(): bool
    {
        return $this->signup_type === 'trial';
    }

    /** Chose to pay up front at registration. */
    public function startedAsPaid(): bool
    {
        return $this->signup_type === 'paid';
    }

    /** Has this business ever paid the platform anything for a plan? */
    public function hasPaidForPlan(): bool
    {
        return SubscriptionPayment::query()->withoutGlobalScopes()->where('tenant_id', $this->id)->where('amount', '>', 0)->exists();
    }

    /** Must this business register a license before it can work? */
    public function needsLicense(): bool
    {
        return (bool) config('license.enforce') && ! $this->license_exempt;
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Stop a tenant trading without touching a row of its data.
     *
     * Suspension is the answer to an unpaid subscription, not deletion: the
     * business still owns its ledger, its invoices and its stock,
     * and will want them back the day it pays.
     */
    public function suspend(string $reason): void
    {
        $this->forceFill([
            'is_active' => false,
            'suspended_at' => now(),
            'suspend_reason' => $reason,
        ])->save();
    }

    public function restore_(): void
    {
        $this->forceFill([
            'is_active' => true,
            'suspended_at' => null,
            'suspend_reason' => null,
        ])->save();
    }

    public function isSuspended(): bool
    {
        return ! $this->is_active;
    }

    /**
     * Shut by the billing engine, as opposed to by a person.
     *
     * Matched on the wording SubscriptionService writes into
     * `suspend_reason`. A tenant suspended for a dispute or an investigation
     * carries a reason of somebody's own, and paying an invoice must not be
     * a way back in from that.
     */
    public function isSuspendedForBilling(): bool
    {
        return $this->isSuspended() && str_starts_with((string) $this->suspend_reason, 'Subscription ');
    }

    public function displayName(): string
    {
        return $this->legal_name ?: $this->name;
    }

    /** Lettermark for the switcher and the list, when there is no logo. */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /** Single-line postal address, empty parts dropped. */
    public function addressLine(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->pincode,
        ])->filter()->implode(', ');
    }

    /** Public URL of the logo, or null when it is missing from disk. */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        // Tolerant of a file that has gone: the chrome falls back to a
        // lettermark rather than rendering a broken image.
        return $disk->exists($this->logo_path) ? $disk->url($this->logo_path) : null;
    }

    protected static function slugFallback(): string
    {
        return 'tenant';
    }
}
