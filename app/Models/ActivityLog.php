<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    protected $fillable = [
        // Stamped by record(), never by a form: see the comment there.
        'tenant_id',
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
            /*
             | Whose log this entry belongs to.
             |
             | The company in context first, because that is the business the
             | action was taken against - a Super Admin helping a customer is
             | writing that customer's history, not the platform's. The
             | actor's own company second, for anything outside a tenant
             | context. Null is a real answer and means the platform: the
             | scheduler, the installer, a console command, and it is what
             | keeps those visible to a Super Admin and to nobody else.
             */
            'tenant_id' => CurrentTenant::id() ?? $user?->getAttribute('tenant_id'),
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
