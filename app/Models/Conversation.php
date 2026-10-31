<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private thread between exactly two people.
 *
 * Deliberately not tenant-scoped: the platform's staff talk to restaurants, so
 * a pair can span two companies. The boundary is the participant check in
 * MessageService, and every query goes through scopeInvolving().
 */
class Conversation extends Model
{
    protected $fillable = ['user_one_id', 'user_two_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id')->withoutGlobalScopes();
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id')->withoutGlobalScopes();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id));
    }

    public function includes(User $user): bool
    {
        return in_array($user->id, [$this->user_one_id, $this->user_two_id], true);
    }

    public function otherId(User $user): int
    {
        return $this->user_one_id === $user->id ? $this->user_two_id : $this->user_one_id;
    }
}
