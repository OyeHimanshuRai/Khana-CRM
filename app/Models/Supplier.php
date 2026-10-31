<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A supplier of one shop.
 *
 * The mirror of Customer: `balance` positive means the shop owes them, and
 * it is written only by the purchase ledger, never by a form.
 */
class Supplier extends Model
{
    use BelongsToShop, SoftDeletes;

    protected $fillable = [
        'shop_id', 'code', 'name', 'company', 'contact_person',
        'mobile', 'alt_mobile', 'email', 'gstin', 'pan',
        'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pincode',
        'credit_days', 'credit_limit', 'opening_balance',
        'bank_name', 'bank_account', 'bank_ifsc',
        'notes', 'is_active',
    ];

    /* `balance` is derived from the purchase ledger, so it is not fillable. */

    protected function casts(): array
    {
        return [
            'credit_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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
            ->orWhere('company', 'like', "%{$term}%")
            ->orWhere('contact_person', 'like', "%{$term}%")
            ->orWhere('mobile', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->orWhere('gstin', 'like', "%{$term}%"));
    }

    /** Anyone the shop currently owes. */
    public function scopeWithPayables(Builder $query): Builder
    {
        return $query->where('balance', '>', 0);
    }

    /* --------------------------------------------------------- accessors */

    public function initials(): string
    {
        return Str::of($this->company ?: $this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /** The trading name if there is one, else the person. */
    public function displayName(): string
    {
        return $this->company ?: $this->name;
    }

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

    public function reference(): string
    {
        return $this->code ?: ($this->mobile ?: '#'.$this->id);
    }

    /** Mint the next shop-local supplier code. */
    public static function nextCode(int $shopId): string
    {
        $last = static::allShops()
            ->withTrashed()
            ->where('shop_id', $shopId)
            ->where('code', 'like', 'S%')
            ->orderByDesc('id')
            ->value('code');

        $number = $last ? ((int) Str::after($last, 'S')) + 1 : 1;

        return 'S'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
