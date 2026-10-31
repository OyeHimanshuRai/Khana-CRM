<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One outbound email, whatever sent it.
 *
 * Fed by the listeners in App\Listeners\RecordOutgoingMail, so this covers
 * every send the app makes - campaigns, welcome emails, password-set links,
 * test sends - rather than only the ones somebody remembered to log.
 *
 * Metadata only, never bodies. See the migration for why that matters.
 */
class EmailLog extends Model
{
    /** Handed to the transport; not yet confirmed either way. */
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUSES = [
        self::PENDING => 'Pending',
        self::SENT => 'Sent',
        self::FAILED => 'Failed',
    ];

    /*
     | Nothing is fillable. Every column is written by the mail listeners,
     | never from a form - this table is a record, not an editable resource.
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if (blank($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('to_email', 'like', "%{$term}%")
            ->orWhere('to_name', 'like', "%{$term}%")
            ->orWhere('subject', 'like', "%{$term}%")
            ->orWhere('from_email', 'like', "%{$term}%")
            ->orWhere('error', 'like', "%{$term}%"));
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    /* --------------------------------------------------------- accessors */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? Str::headline((string) $this->status);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::SENT => 'badge-success',
            self::FAILED => 'badge-danger',
            default => 'badge-warning',
        };
    }

    /**
     * The short name of the mailable that produced this, for the listing.
     *
     * "Welcome User Mail" reads better in a table than the fully qualified
     * class name, and the full name is still on the detail screen.
     */
    public function kindLabel(): string
    {
        if (blank($this->mailable)) {
            return 'Direct';
        }

        return Str::headline(class_basename($this->mailable));
    }

    public function displayName(): string
    {
        return filled($this->to_name) ? $this->to_name : '—';
    }

    /**
     * A pending row that is not going to resolve.
     *
     * Neither listener fired to finish it, which in practice means the
     * process died mid-send. Shown as its own thing rather than silently
     * counted as sent.
     */
    public function isStuck(): bool
    {
        return $this->status === self::PENDING
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(15));
    }

    /** @return Collection<int, string> */
    public function extraRecipients(): Collection
    {
        return collect([
            'To' => $this->to_extra,
            'Cc' => $this->cc,
            'Bcc' => $this->bcc,
        ])->filter();
    }

    /* ------------------------------------------------------------- stats */

    /**
     * Headline figures for the listing's stat cards.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $counts = static::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'sent' => (int) $counts->get(self::SENT, 0),
            'failed' => (int) $counts->get(self::FAILED, 0),
            'today' => static::where('created_at', '>=', now()->startOfDay())->count(),
        ];
    }
}
