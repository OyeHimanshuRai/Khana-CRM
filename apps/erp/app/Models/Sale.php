<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill ERP has heard about - from CRM by event, from a CSV import, or
 * from other billing software through the sales API.
 *
 * ERP keeps it for two reasons: to consume the stock of anything on it that
 * did not go through the kitchen, and to know what was sold when it works
 * out food cost.
 */
class Sale extends Model
{
    use InOutlet;

    public const RECORDED = 'recorded';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'outlet_id', 'source', 'ref', 'invoice_no', 'business_date', 'channel',
        'net_paise', 'tax_paise', 'round_off_paise', 'grand_paise', 'status', 'cancel_reason', 'sales_import_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'net_paise' => 'integer',
            'tax_paise' => 'integer',
            'round_off_paise' => 'integer',
            'grand_paise' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
