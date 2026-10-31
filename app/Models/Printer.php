<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A machine that puts ink on paper (SRS 4, 6, 8, 21).
 *
 * @property string $kind
 * @property string $driver
 * @property int $columns
 */
class Printer extends Model
{
    use BelongsToShop;
    use SoftDeletes;

    public const KOT = 'kot';

    public const BILL = 'bill';

    public const LABEL = 'label';

    public const REPORT = 'report';

    /** @var array<string, string> */
    public const KINDS = [
        self::KOT => 'Kitchen ticket (KOT)',
        self::BILL => 'Customer bill',
        self::LABEL => 'Labels',
        self::REPORT => 'Reports',
    ];

    /** The operating system's print dialog - what this system always did. */
    public const BROWSER = 'browser';

    /** A socket to the printer, speaking ESC/POS. */
    public const NETWORK = 'network';

    /** @var array<string, string> */
    public const DRIVERS = [
        self::BROWSER => 'Browser print dialog',
        self::NETWORK => 'Network printer (ESC/POS)',
    ];

    protected $fillable = [
        'shop_id', 'name', 'code', 'kind', 'driver',
        'kitchen_station_id', 'host', 'port', 'columns', 'copies',
        'auto_cut', 'is_default', 'is_active', 'notes', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'columns' => 'integer',
            'copies' => 'integer',
            'auto_cut' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<KitchenStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /** @return HasMany<PrintJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Can this printer actually be sent to without a person present?
     *
     * The distinction the kitchen cares about. A browser printer needs
     * somebody at a screen to press the button and pick the machine; a
     * network one does not, which is the entire reason a tandoor gets its own
     * ticket while the till is at the front of the shop.
     */
    public function isAutomatic(): bool
    {
        return $this->driver === self::NETWORK && filled($this->host);
    }

    /**
     * Configured well enough to try.
     *
     * A network printer with no host is a row somebody started and did not
     * finish. It is reported rather than silently skipped, because a kitchen
     * that thinks it has a printer and has not is worse off than one that
     * knows it has none.
     */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->driver === self::BROWSER || filled($this->host);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function driverLabel(): string
    {
        return self::DRIVERS[$this->driver] ?? ucfirst((string) $this->driver);
    }

    /** "192.168.1.50:9100", or the reason there is no address. */
    public function addressLabel(): string
    {
        if ($this->driver === self::BROWSER) {
            return 'Whatever the browser is pointed at';
        }

        return filled($this->host) ? $this->host.':'.$this->port : 'No address set';
    }
}
