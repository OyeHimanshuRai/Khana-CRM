<?php

namespace App\Models;

use App\Support\Agent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One sign-in, with the device it happened on.
 *
 * The framework's own `sessions` table decides whether a session still
 * works; this row is the readable record around it and outlives it.
 */
class UserSession extends Model
{
    /** Minutes of silence after which a session is treated as offline. */
    public const ONLINE_WINDOW = 5;

    protected $fillable = [
        'user_id', 'session_id', 'ip_address',
        'device_type', 'device_name', 'browser', 'browser_version',
        'operating_system', 'os_version', 'location', 'user_agent',
        'login_at', 'last_activity_at', 'logout_at', 'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    /** Sessions that have not been signed out or expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('logout_at');
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->active()
            ->where('last_activity_at', '>=', now()->subMinutes(self::ONLINE_WINDOW));
    }

    /* --------------------------------------------------------- accessors */

    public function isActive(): bool
    {
        return $this->logout_at === null;
    }

    public function isOnline(): bool
    {
        return $this->isActive()
            && $this->last_activity_at?->gt(now()->subMinutes(self::ONLINE_WINDOW)) === true;
    }

    /** True when this row is the session making the current request. */
    public function isCurrent(?string $currentSessionId): bool
    {
        return $currentSessionId !== null && $this->session_id === $currentSessionId;
    }

    public function status(): string
    {
        if ($this->logout_at !== null) {
            return match ($this->ended_by) {
                'admin' => 'Signed out by admin',
                'expired' => 'Expired',
                default => 'Signed out',
            };
        }

        return $this->isOnline() ? 'Online' : 'Idle';
    }

    public function describeDevice(): string
    {
        $parts = array_filter([
            $this->browser,
            $this->browser_version,
            'on',
            $this->operating_system,
            $this->os_version,
        ]);

        return $parts === [] ? 'Unknown device' : implode(' ', $parts);
    }

    /* ---------------------------------------------------------- creation */

    /**
     * Open a session row for the user who has just signed in.
     */
    public static function start(User $user, Request $request): self
    {
        $agent = Agent::of($request->userAgent());

        return static::create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'login_at' => now(),
            'last_activity_at' => now(),
            ...$agent->toArray(),
        ]);
    }

    /**
     * Close this session and, unless it is already gone, delete the record
     * the framework authenticates against - which is what actually signs the
     * device out rather than just labelling the row.
     */
    public function terminate(string $endedBy = 'user'): void
    {
        if (filled($this->session_id)) {
            static::forgetFrameworkSession($this->session_id);
        }

        $this->forceFill([
            'logout_at' => now(),
            'ended_by' => $endedBy,
        ])->save();
    }

    /**
     * Drop a row from Laravel's session store.
     *
     * Only meaningful on the database driver; on file or redis the store is
     * elsewhere, so this quietly does nothing and the row is still marked
     * signed out.
     */
    public static function forgetFrameworkSession(string $sessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('id', $sessionId)
            ->delete();
    }
}
