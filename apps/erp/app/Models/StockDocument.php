<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of store-room paperwork: an issue to the kitchen, a wastage note,
 * a production batch or a stock count. Written by App\Services\StoreRoom
 * together with the ledger rows it causes.
 */
class StockDocument extends Model
{
    use InOutlet;

    public const ISSUE = 'issue';

    public const WASTAGE = 'wastage';

    public const PRODUCTION = 'production';

    public const COUNT = 'count';

    public const PREFIXES = [
        self::ISSUE => 'ISS',
        self::WASTAGE => 'WST',
        self::PRODUCTION => 'PRD',
        self::COUNT => 'CNT',
    ];

    public const WASTAGE_REASONS = [
        'Spoiled', 'Expired', 'Spilt or dropped', 'Overcooked or burnt', 'Returned by guest', 'Staff meal', 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'kind', 'number', 'on_date', 'store_id', 'to_store_id', 'note', 'value_paise', 'user_id',
    ];

    protected function casts(): array
    {
        return ['on_date' => 'date', 'value_paise' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
