<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use HasPublicId;
    use HasUniqueSlug;
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'slug', 'legal_name',
        'gstin', 'pan',
        'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pincode', 'country',
        'logo_path',
        'currency', 'timezone',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Switched off here.
     *
     * Suspending a business is Khana Accounts' decision now, and reaches CRM
     * through the kit's reference copy - see EnsureTenantIsActive, which
     * reads both. This is the local half: a business that was switched off
     * before the split still reads as off.
     */
    public function isSuspended(): bool
    {
        return ! $this->is_active;
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
