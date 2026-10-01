<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What was asked of a supplier. Optional - a delivery can be received with
 * no order behind it - but when there is one, the receipt is checked off
 * against it.
 */
class PurchaseOrder extends Model
{
    use InOutlet;

    public const OPEN = 'open';

    public const PART_RECEIVED = 'part_received';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::OPEN => 'Waiting for delivery',
        self::PART_RECEIVED => 'Part received',
        self::RECEIVED => 'Received',
        self::CANCELLED => 'Cancelled',
    ];

    protected $fillable = ['tenant_id', 'outlet_id', 'supplier_id', 'number', 'ordered_on', 'expected_on', 'status', 'total_paise', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'expected_on' => 'date', 'total_paise' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::PART_RECEIVED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::RECEIVED => 'success',
            self::PART_RECEIVED => 'warning',
            self::CANCELLED => 'danger',
            default => 'info',
        };
    }
}
