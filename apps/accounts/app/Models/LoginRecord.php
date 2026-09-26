<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One sign-in attempt, good or bad.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $identifier
 * @property string $status
 * @property string|null $reason
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon $created_at
 */
class LoginRecord extends Model
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    public const LOCKED = 'locked';

    public const UPDATED_AT = null;

    protected $table = 'login_history';

    protected $fillable = ['user_id', 'identifier', 'status', 'reason', 'ip_address', 'user_agent'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $status, ?string $identifier, ?User $user, ?string $reason, Request $request): self
    {
        return self::query()->create([
            'user_id' => $user?->id,
            'identifier' => $identifier !== null ? Str::limit($identifier, 250, '') : null,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
        ]);
    }
}
