<?php

namespace App\Models;

use App\Models\Concerns\OwnTenantUsersScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    /**
     * A company's staff reach the rows their own company's accounts wrote,
     * and no others. See OwnTenantUsersScope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnTenantUsersScope());
    }

    protected $fillable = [
        'user_id',
        'user_name',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Write an audit entry for the current actor.
     *
     * `user_name` is denormalised on purpose: the name is captured as it was
     * at the time of the action, and the row stays readable after the user
     * is renamed or deleted.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = []
    ): self {
        $user = Auth::user();

        return static::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
            'event' => $event,
            'description' => $description,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 500, ''),
        ]);
    }

    /** Free-text search across actor, description and event. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('description', 'like', "%{$term}%")
                ->orWhere('user_name', 'like', "%{$term}%")
                ->orWhere('event', 'like', "%{$term}%");
        });
    }
}
