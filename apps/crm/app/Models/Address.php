<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in a customer's storefront address book.
 *
 * Never referenced after checkout - Order snapshots the chosen address onto
 * itself, so editing or deleting a row here cannot change what an existing
 * order says. See Order's docblock.
 */
class Address extends Model
{
    protected $fillable = [
        'shop_id', 'customer_id', 'label', 'recipient_name', 'mobile',
        'address_line1', 'address_line2', 'village', 'taluka', 'district',
        'city', 'state', 'pincode', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Single-line address, empty parts dropped. */
    public function line(): string
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
}
