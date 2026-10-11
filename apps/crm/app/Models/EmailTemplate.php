<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reusable email content.
 *
 * A template is a starting point, not a live binding: sending copies its
 * subject, preheader and body into the message, which then owns them. Editing
 * a template afterwards changes nothing that has already been sent, which is
 * the only behaviour that keeps an old delivery log honest.
 */
class EmailTemplate extends Model
{
    use HasUniqueSlug, SoftDeletes;

    /**
     * What the merge tags in a template's content resolve to when it is sent.
     *
     * Kept on the model rather than on whatever sends it, because the form
     * that writes the content and the service that renders it must agree
     * about the list, and a second copy is how they stop agreeing.
     *
     * @var array<string, string>
     */
    public const MERGE_TAGS = [
        '{{name}}' => 'Recipient name, or "there" when it is unknown',
        '{{email}}' => 'Recipient email address',
        '{{company}}' => 'Company name from Settings > General',
    ];

    protected $fillable = [
        'name', 'slug', 'category', 'description',
        'subject', 'preheader', 'content', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** Match the column defaults in PHP as well as in the database. */
    protected $attributes = [
        'is_active' => true,
        'usage_count' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            if (blank($template->uuid)) {
                $template->uuid = (string) Str::uuid();
            }
        });

        /*
         | Keep `variables` in step with the body on every write.
         |
         | Derived rather than declared: a list somebody types in drifts out
         | of step the first time they edit one and not the other.
         */
        static::saving(function (self $template): void {
            $template->variables = $template->detectVariables();
        });
    }

    protected static function slugFallback(): string
    {
        return 'template';
    }

    /* --------------------------------------------------------- relations */

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('subject', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhere('category', 'like', "%{$term}%"));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* --------------------------------------------------------- variables */

    /**
     * Which merge tags this template's content actually contains.
     *
     * @return array<int, string>
     */
    public function detectVariables(): array
    {
        $body = (string) $this->content.' '.(string) $this->subject.' '.(string) $this->preheader;

        return collect(array_keys(self::MERGE_TAGS))
            ->filter(fn (string $tag) => str_contains($body, $tag))
            ->values()
            ->all();
    }

    /** @return Collection<int, string> */
    public function variableList(): Collection
    {
        return collect($this->variables ?? [])->filter()->values();
    }

    /* --------------------------------------------------------- accessors */

    public function statusLabel(): string
    {
        return $this->is_active ? 'Active' : 'Inactive';
    }

    public function statusTone(): string
    {
        return $this->is_active ? 'badge-success' : 'badge-danger';
    }

    public function authorLabel(): string
    {
        // The relation first, then the name captured when it was written -
        // which is what survives the account being deleted.
        return $this->author?->name ?? ($this->created_by_name ?: 'Unknown');
    }

    /**
     * A rough read of how long the body is, for the listing.
     *
     * Words rather than characters: "820 words" tells an editor something,
     * "5,200 characters" does not.
     */
    public function wordCount(): int
    {
        return Str::wordCount(strip_tags((string) $this->content));
    }

    /**
     * The categories already in use, for the filter and the form.
     *
     * Derived from the rows rather than a table of its own, so the list
     * cleans itself up when the last template using a label goes.
     *
     * @return Collection<int, string>
     */
    public static function categories(): Collection
    {
        return static::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }
}
