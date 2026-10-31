<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A customer of one shop.
 *
 * `balance` is a maintained running total: positive means the customer owes
 * the shop. It is written only by the ledger service, never by a form - a
 * balance you can type into is not a balance anyone can trust.
 *
 * Extends Authenticatable, the same base `User` does, so a customer can also
 * sign in to that shop's storefront on the separate `customer` guard
 * (config/auth.php) - without disturbing any relation, scope or business
 * method already written against this class.
 */
class Customer extends Authenticatable
{
    use BelongsToShop, Notifiable, SoftDeletes;

    /** What kind of buyer this is; drives default pricing later. */
    public const TYPES = [
        'retail' => 'Retail',
        'farmer' => 'Farmer',
        'wholesale' => 'Wholesale',
        'dealer' => 'Dealer',
    ];

    protected $fillable = [
        'shop_id', 'user_id', 'code', 'name',
        'mobile', 'alt_mobile', 'email', 'password', 'gstin',
        'address_line1', 'address_line2', 'village', 'taluka', 'district',
        'city', 'state', 'pincode',
        'land_area', 'primary_crops',
        'type', 'credit_limit', 'credit_days', 'opening_balance', 'allow_credit',
        'notes', 'is_active',
    ];

    /*
     | `balance` and `image_path` are deliberately not fillable. The balance
     | is derived from the ledger; the photo only ever arrives through the
     | controller's upload handler.
     */

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'land_area' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'opening_balance' => 'decimal:2',
            'balance' => 'decimal:2',
            'allow_credit' => 'boolean',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /**
     * Set when this customer was also created as (or linked to) an admin
     * account - unrelated to storefront login, which this class now handles
     * itself as an Authenticatable on the `customer` guard.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default');
    }

    public function cart(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('placed_at');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('mobile', 'like', "%{$term}%")
            ->orWhere('alt_mobile', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->orWhere('gstin', 'like', "%{$term}%")
            ->orWhere('village', 'like', "%{$term}%"));
    }

    /** Anyone who currently owes the shop money. */
    public function scopeWithDues(Builder $query): Builder
    {
        return $query->where('balance', '>', 0);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('type', $type);
    }

    /* ------------------------------------------------------------ credit */

    /**
     * How much more this customer may be allowed to owe.
     *
     * Never negative: a customer already over their limit has no headroom,
     * not negative headroom, and the difference matters at the till.
     */
    public function availableCredit(): float
    {
        if (! $this->allow_credit) {
            return 0.0;
        }

        return max(0.0, (float) $this->credit_limit - (float) $this->balance);
    }

    /**
     * Whether a credit sale of this size is permitted.
     *
     * Two separate refusals on purpose: a customer with credit switched off
     * is a policy decision, and one who is merely at their limit is a
     * commercial one. The caller usually wants to word them differently.
     */
    public function canTakeCredit(float $amount): bool
    {
        return $this->allow_credit && $amount <= $this->availableCredit();
    }

    public function isOverLimit(): bool
    {
        return $this->allow_credit && (float) $this->balance > (float) $this->credit_limit;
    }

    /* --------------------------------------------------------- accessors */

    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->image_path) ? $disk->url($this->image_path) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    /** Single-line address, empty parts dropped. */
    public function addressLine(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            $this->village,
            $this->taluka,
            $this->district,
            $this->city,
            $this->state,
            $this->pincode,
        ])->filter()->implode(', ');
    }

    /** What the counter shows next to the name. */
    public function reference(): string
    {
        return $this->mobile ?: ($this->code ?: '#'.$this->id);
    }

    /**
     * Mint the next customer code for a shop.
     *
     * Sequential and shop-local, so the codes stay short and readable rather
     * than being global ids that jump about.
     */
    public static function nextCode(int $shopId): string
    {
        $last = static::allShops()
            ->withTrashed()
            ->where('shop_id', $shopId)
            ->where('code', 'like', 'C%')
            ->orderByDesc('id')
            ->value('code');

        $number = $last ? ((int) Str::after($last, 'C')) + 1 : 1;

        return 'C'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
