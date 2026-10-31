<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message sent to a lot of people (SRS 15, 21).
 *
 * @property array<string, mixed>|null $segment
 * @property string $status
 */
class Campaign extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    /** Being sent right now. The lock that stops a second run starting. */
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => 'muted'],
        self::SCHEDULED => ['label' => 'Scheduled', 'tone' => 'info'],
        self::SENDING => ['label' => 'Sending', 'tone' => 'warning'],
        self::SENT => ['label' => 'Sent', 'tone' => 'success'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    /** @var array<int, string> */
    public const CHANNELS = ['sms', 'whatsapp'];

    protected $fillable = [
        'shop_id', 'name', 'channel', 'body', 'template', 'segment',
        'status', 'scheduled_for', 'started_at', 'finished_at',
        'audience_count', 'sent_count', 'failed_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'segment' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'audience_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Campaigns the sender should pick up.
     *
     * `sending` is included so a run interrupted half way - a deploy, a
     * timeout, a machine going down - is resumed rather than abandoned. The
     * recipient rows say who already had it, so resuming cannot send twice.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::SCHEDULED, self::SENDING])
            ->where(fn (Builder $q) => $q
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', now()));
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SENT, self::CANCELLED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? 'muted';
    }

    public function channelLabel(): string
    {
        return $this->channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
    }

    /** How far through, for the list. */
    public function progress(): int
    {
        if ($this->audience_count <= 0) {
            return 0;
        }

        return (int) round((($this->sent_count + $this->failed_count) / $this->audience_count) * 100);
    }

    /**
     * The message with a recipient's details filled in.
     *
     * Only the name, and deliberately. Every extra placeholder is another way
     * for a campaign to go out reading "Dear ," to four hundred people, and
     * the merge that actually earns its keep is the first one.
     */
    public function renderFor(?string $name): string
    {
        return str_replace(
            ['{name}', '{Name}'],
            $name ?: 'there',
            (string) $this->body,
        );
    }
}
