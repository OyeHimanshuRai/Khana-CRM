<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Support\CurrentTenant;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A shop is the tenant boundary. Everything operational - products' stock,
 * customers, invoices, payments, purchases, orders - belongs to exactly one.
 *
 * Deleting is soft on purpose: an invoice must still be able to name the
 * shop that raised it years later.
 */
class Shop extends Model
{
    use HasUniqueSlug, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name', 'code', 'slug', 'legal_name',
        'gstin', 'pan', 'licence_no',
        'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pincode', 'country',
        'invoice_prefix', 'pos_prefix',
        'currency', 'timezone',
        'upi_id', 'upi_name',
        'allow_negative_stock', 'block_expired_sale', 'recipe_deduction',
        'modules',
        'requires_otp',
        'is_active', 'sort_order',
    ];

    /*
     | logo_path, invoice_next and pos_next are deliberately not fillable.
     | The logo is only ever set by the controller's upload handler, and the
     | two counters only ever move through nextInvoiceNumber(), which holds a
     | row lock while it does so.
     */

    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'block_expired_sale' => 'boolean',
            // Null and [] are different answers here - see the migration.
            'modules' => 'array',
            'requires_otp' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'invoice_next' => 'integer',
            'pos_next' => 'integer',
        ];
    }

    /**
     * Does this branch run this line of business?
     *
     * The other half of every access decision in the admin, the first being
     * the user's permission. A branch that does no manufacturing hides it
     * from everybody including its owner; a branch that does hides it from a
     * cashier, because the cashier has no permission. Neither substitutes
     * for the other.
     */
    public function hasModule(string $module): bool
    {
        return in_array($module, Modules::forShop($this), true);
    }

    /**
     * The modules this branch runs, as labels, for the list and the detail
     * screen.
     *
     * @return array<int, string>
     */
    public function moduleLabels(): array
    {
        $catalogue = Modules::catalogue();

        return array_values(array_map(
            fn (string $key) => $catalogue[$key]['label'] ?? $key,
            Modules::forShop($this),
        ));
    }

    /**
     * A new branch joins the business it was opened from.
     *
     * Unlike a user, a branch always belongs to a company - there is no such
     * thing as a shop that belongs to none. That is the difference from
     * User::booted(), which leaves tenant_id null when there is nothing in
     * context, because for an account null is a real answer: it means Super
     * Admin. Here it would only ever mean a branch nobody can reach.
     *
     * Falls back to the sole company when there is no request context at all,
     * which is the seeder and console case. CurrentTenant::soleId() returns
     * null the moment a second business exists, so this can never guess.
     */
    protected static function booted(): void
    {
        static::creating(function (Shop $shop) {
            if ($shop->getAttribute('tenant_id') === null) {
                $shop->setAttribute('tenant_id', CurrentTenant::id() ?? CurrentTenant::soleId());
            }
        });

        /*
         | And it never leaves it.
         |
         | The invariant above is only half the story while an update can
         | blank the column: a fill() carrying no company wrote null over a
         | correct tenant, and creating() does not fire to put it back. One
         | shop was orphaned that way by an edit that only touched its
         | address - see ShopController::attributes, which now passes the
         | row it is editing.
         |
         | This is the guard behind that fix rather than a repeat of it, and
         | it restores rather than refuses: an edit that never mentioned a
         | company did not intend to move the branch out of one, so leaving
         | it where it is, is the honest reading of it. A caller that does
         | name a tenant - moving a branch between businesses - passes a
         | real id and is not touched here.
         */
        static::updating(function (Shop $shop) {
            if ($shop->getAttribute('tenant_id') === null) {
                $shop->setAttribute('tenant_id', $shop->getOriginal('tenant_id'));
            }
        });
    }

    /* --------------------------------------------------------- relations */

    /**
     * The business this branch belongs to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Every subscription this outlet has held, and the one that counts.
     *
     * Latest wins, exactly as it does per tenant: changing plan writes a new
     * row and renewing moves the dates on the existing one, so the newest
     * row is the current agreement. Deliberately not filtered by state - an
     * expired subscription is still this outlet's subscription, and it is
     * the row somebody has to be shown to understand why the branch stopped
     * working.
     *
     * Blanket rows (shop_id null) are NOT reached from here. They belong to
     * the tenant and are resolved as a fallback in PlanAccess, which is the
     * one place that knows the order to look in.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Free-text across the fields the toolbar searches. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('legal_name', 'like', "%{$term}%")
            ->orWhere('city', 'like', "%{$term}%")
            ->orWhere('gstin', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%"));
    }

    /* -------------------------------------------------- invoice numbering */

    /**
     * Claim the next number in one of this shop's series.
     *
     * Serialised with a row lock rather than a MAX(...)+1 read, because two
     * cashiers pressing Save in the same second is the normal case at a
     * counter, not an edge one - and a duplicate invoice number is the kind
     * of error that surfaces at audit time, months later.
     *
     * @param  'invoice'|'pos'  $series
     */
    public function nextNumber(string $series = 'invoice'): string
    {
        $column = $series === 'pos' ? 'pos_next' : 'invoice_next';
        $prefixColumn = $series === 'pos' ? 'pos_prefix' : 'invoice_prefix';

        return DB::transaction(function () use ($column, $prefixColumn) {
            /** @var self $locked */
            $locked = static::query()->lockForUpdate()->findOrFail($this->id);

            $sequence = (int) $locked->{$column};
            $locked->forceFill([$column => $sequence + 1])->save();

            // Keep the in-memory instance honest for anything that reads it
            // later in the same request.
            $this->{$column} = $sequence + 1;

            return sprintf(
                '%s/%s/%s/%05d',
                $locked->{$prefixColumn},
                $locked->code,
                now()->format('Y'),
                $sequence,
            );
        });
    }

    /* --------------------------------------------------------- accessors */

    /** Public URL of the uploaded logo, or null. */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->logo_path) ? $disk->url($this->logo_path) : null;
    }

    /** First letters of the name, for the no-logo placeholder. */
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
}
