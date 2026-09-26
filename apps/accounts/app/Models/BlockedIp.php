<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An address refused at sign-in: for everybody, or for one person.
 *
 * @property int $id
 * @property string $ip_address
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class BlockedIp extends Model
{
    protected $fillable = ['ip_address', 'user_id', 'reason', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @param  Builder<self>  $query */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Is this address blocked for everybody, or for this person? */
    public static function blocks(string $ip, ?User $user = null): bool
    {
        return self::query()->current()
            ->where('ip_address', $ip)
            ->where(fn ($q) => $q->whereNull('user_id')->when($user, fn ($q) => $q->orWhere('user_id', $user->id)))
            ->exists();
    }
}
