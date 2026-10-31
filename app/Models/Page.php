<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A standalone page on the public site (privacy policy, terms, about...).
 *
 * The body is Markdown with raw HTML stripped, so an editor can write headings,
 * lists and links but cannot put a script on a page that sits next to a payment
 * form.
 */
class Page extends Model
{
    protected $fillable = [
        'title', 'slug', 'body', 'meta_description',
        'is_active', 'show_in_footer', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_footer' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Pages linked from the public footer, in order. */
    public function scopeFooter(Builder $query): Builder
    {
        return $query->active()->where('show_in_footer', true)->orderBy('sort_order')->orderBy('title');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', "%{$term}%")
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('body', 'like', "%{$term}%"));
    }

    public function html(): HtmlString
    {
        return new HtmlString(Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    /** A slug that is free, derived from the wanted text. */
    public static function uniqueSlug(string $wanted, ?int $ignoreId = null): string
    {
        $base = Str::slug($wanted) ?: 'page';
        $slug = $base;
        $n = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
