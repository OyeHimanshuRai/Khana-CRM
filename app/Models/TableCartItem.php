<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a table's cart, before it is sent to the kitchen.
 *
 * Written only by App\Services\TableCartService, which is where the rules
 * about sizes and add-ons live. Priced on read rather than on write - see the
 * migration for why a cart is not a promise.
 */
class TableCartItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'table_session_id', 'product_id', 'product_variant_id',
        'quantity', 'note', 'option_ids', 'added_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'option_ids' => 'array',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * The add-on options on this line, in menu order.
     *
     * Read live rather than from a snapshot, because a cart is re-priced
     * every time it is rendered. An option that has since been deleted simply
     * drops out, which is the honest answer: it is no longer orderable.
     *
     * @return Collection<int, ModifierOption>
     */
    public function options(): Collection
    {
        $ids = collect($this->option_ids ?? [])->map(fn ($id) => (int) $id)->filter();

        if ($ids->isEmpty()) {
            return new Collection();
        }

        return ModifierOption::query()
            ->with('modifier:id,name,sort_order')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy([
                fn (ModifierOption $o) => $o->modifier?->sort_order ?? 0,
                fn (ModifierOption $o) => $o->sort_order,
            ])
            ->values();
    }

    /** What one of these costs: the size (or the dish), plus its add-ons. */
    public function unitPrice(string $channel = 'dine_in', ?int $shopId = null): float
    {
        $base = $this->variant
            ? (float) $this->variant->price
            : (float) ($this->product?->channelPriceFor($channel, $shopId) ?? 0);

        return round($base + (float) $this->options()->sum('price'), 2);
    }

    public function lineTotal(string $channel = 'dine_in', ?int $shopId = null): float
    {
        return round($this->unitPrice($channel, $shopId) * max(1, (int) $this->quantity), 2);
    }

    /** "Chicken Biryani — Full", for the cart and the kitchen ticket. */
    public function title(): string
    {
        $name = $this->product?->name ?? 'Item';

        return $this->variant ? $name.' — '.$this->variant->name : $name;
    }

    /**
     * Whether this line can still be sent to the kitchen.
     *
     * Checked when the cart is rendered and again when the order is placed:
     * a dish can sell out between a guest adding it and tapping Order, and
     * the second check is the one that matters.
     */
    public function isOrderable(): bool
    {
        if ($this->product === null || ! $this->product->isOrderable()) {
            return false;
        }

        return $this->variant === null || $this->variant->is_available;
    }

    /** Why it cannot be sent, for a cart line that has to say something. */
    public function unavailableReason(): ?string
    {
        if ($this->product === null) {
            return 'No longer on the menu';
        }

        if ($reason = $this->product->unavailableReason()) {
            return $reason;
        }

        if ($this->variant !== null && ! $this->variant->is_available) {
            return $this->variant->name.' is not available';
        }

        return null;
    }
}
