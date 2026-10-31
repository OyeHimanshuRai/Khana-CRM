<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One QR code issued for one table.
 *
 * Written only by App\Services\TableQrService - issuing and revoking have to
 * happen together or a table ends up with two live codes, and that invariant
 * lives in one place.
 */
class TableQr extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'restaurant_table_id', 'token',
        'issued_at', 'revoked_at', 'revoked_reason', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_scanned_at' => 'datetime',
            'scan_count' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /* --------------------------------------------------------- behaviour */

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Where this code sends a phone.
     *
     * A bare path with the token, no signature and no expiry. A signed URL
     * would be wrong here for a reason worth stating: the URL is printed on a
     * sticker and lives on a table for months, and anything with an expiry in
     * it turns into a dead sticker on a date nobody wrote down. The token
     * *is* the secret, revoking the row is how it is withdrawn, and what the
     * scan opens is a public menu rather than anybody's data.
     */
    public function url(): string
    {
        return url('/t/'.$this->token);
    }
}
