<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\CurrentShop;
use App\Support\StockOuts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One size of one dish: Half, Full, Large, 6".
 *
 * Not shop-scoped, and deliberately so: a variant belongs to a product, the
 * product is global catalogue, and the per-shop overrides live on
 * `product_shop` exactly as they do for the base price. Adding a second
 * scoping rule here would mean two answers to "what does a Large cost".
 *
 * One thing about a size does differ from outlet to outlet, and it is not
 * kept on this row: whether that outlet's stock can still cover it. That is
 * ERP's answer, held per outlet - see isAvailableAt().
 */
class ProductVariant extends Model
{
    use HasPublicId;

    protected $fillable = [
        'product_id', 'name', 'sku', 'price', 'mrp',
        'is_default', 'is_available', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'mrp' => 'decimal:4',
            // Written only by ERP's erp.cost.changed, never by the form - see
            // App\Events\Inbound\ApplyCost.
            'purchase_price' => 'decimal:4',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Whether this size can be ordered at one outlet.
     *
     * Two things have to agree: staff have left it on (`is_available`, for
     * the whole business) and ERP's stock at that outlet can cover it. The
     * outlet defaults to the shop the request is working in; with none, only
     * the staff switch answers - as for the dish, see Product::isSoldOut().
     */
    public function isAvailableAt(?int $shopId = null): bool
    {
        return $this->is_available && ! $this->isOutOfStock($shopId);
    }

    /** Whether ERP's stock has taken this size off that outlet's menu. */
    public function isOutOfStock(?int $shopId = null): bool
    {
        return StockOuts::sizeOut($shopId ?? CurrentShop::id(), (int) $this->product_id, (int) $this->id);
    }

    /** "Biryani — Full", for a KOT line or a bill. */
    public function fullName(): string
    {
        return trim(($this->product?->name ?? '').' — '.$this->name, ' —');
    }

    /**
     * Make this the size the menu opens on, demoting its siblings.
     *
     * Not in a transaction, and that is a considered difference from
     * Warehouse::makeDefault(): two rows briefly both flagged here shows the
     * customer menu a second size pre-selected for a few milliseconds.
     * Nobody is billed wrongly and nothing reconciles against it.
     */
    public function makeDefault(): void
    {
        static::query()
            ->where('product_id', $this->product_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
