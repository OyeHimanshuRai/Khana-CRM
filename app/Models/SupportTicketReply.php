<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a support thread (§2).
 *
 * Carries no scoping of its own. It is only ever reached through its ticket,
 * and the ticket is what decides who may see it - see
 * SupportTicket::scopeVisibleTo(). A reply loaded without its ticket is a bug
 * in the caller rather than a hole here.
 *
 * @property bool $from_staff
 * @property bool $internal
 */
class SupportTicketReply extends Model
{
    protected $fillable = [
        'support_ticket_id', 'user_id', 'author_name',
        'from_staff', 'internal', 'body',
    ];

    protected function casts(): array
    {
        return [
            'from_staff' => 'boolean',
            'internal' => 'boolean',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ------------------------------------------------------------ scopes */

    /** Everything the restaurant is allowed to read. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('internal', false);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * The name to print above the message.
     *
     * The stored copy wins over the live user record - see the migration for
     * why. The relation is only consulted when the copy is somehow empty,
     * which should not happen and is cheap to survive if it does.
     */
    public function byLabel(): string
    {
        return $this->author_name ?: ($this->author?->name ?? 'Deleted user');
    }

    /** Which side of the conversation to draw this on. */
    public function side(): string
    {
        return $this->from_staff ? 'support' : 'customer';
    }
}
