<?php

namespace App\Models;

use App\Support\Agent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Every login attempt, successful or not.
 *
 * Also the source of a user's IP history: an address is only interesting
 * here because something tried to sign in from it, so there is no second
 * table duplicating that.
 */
class LoginHistory extends Model
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    protected $fillable = [
        'user_id', 'email', 'status', 'reason', 'ip_address',
        'device_type', 'device_name', 'browser', 'browser_version',
        'operating_system', 'os_version', 'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::FAILED, self::BLOCKED]);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /** Human wording for why an attempt was turned away. */
    public function describeReason(): ?string
    {
        return match ($this->reason) {
            'bad_password' => 'Wrong password',
            'unknown_email' => 'No account with that email',
            'inactive' => 'Account deactivated',
            'no_admin_access' => 'No administrator access',
            'ip_blocked' => 'IP address blocked',
            'throttled' => 'Too many attempts',
            default => $this->reason,
        };
    }

    /**
     * Record one attempt.
     *
     * The request is optional so this can be called from a FormRequest, a
     * controller or a listener without threading one through.
     */
    public static function record(
        string $status,
        ?string $email,
        ?User $user = null,
        ?string $reason = null,
        ?Request $request = null,
    ): self {
        $userAgent = $request?->userAgent() ?? RequestFacade::userAgent();
        $ip = $request?->ip() ?? RequestFacade::ip();

        return static::create([
            'user_id' => $user?->id,
            'email' => $email,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => mb_substr((string) $userAgent, 0, 1000),
            ...Agent::of($userAgent)->toArray(),
        ]);
    }
}
