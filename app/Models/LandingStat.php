<?php

namespace App\Models;

use App\Models\Concerns\LandingContent;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A trust number on the landing page (§19).
 *
 * ---------------------------------------------------------------------------
 * Typed numbers go stale; counted ones cannot
 * ---------------------------------------------------------------------------
 *
 * `value` is a string because these are written by a marketer: "1,50,000+",
 * "24/7", "99.9%". None of those are integers.
 *
 * But a typed number is a claim nobody revisits. It is right on the day it is
 * entered and slowly becomes a lie. So a row may instead name a `source` - a
 * thing this application can count for itself - and the page prints the real
 * figure at render time.
 *
 * That is the point of this model. Given the choice, count it.
 */
class LandingStat extends Model
{
    use LandingContent;

    /**
     * What can be counted, and what each one is called on the form.
     *
     * Deliberately short. Everything here is a plain count of a table this
     * deployment owns - nothing derived, nothing that needs a date range,
     * because a statistic that needs explaining is not a trust number.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'tenants' => 'Businesses on the platform',
        'shops' => 'Outlets on the platform',
        'orders' => 'Orders processed',
        'menu_items' => 'Menu items managed',
        'modules' => 'Modules in the product',
        'integrations' => 'Integrations listed',
    ];

    protected $fillable = [
        'label', 'value', 'source', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('label', 'like', "%{$term}%")
            ->orWhere('value', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /** Is this row counted by the software rather than typed? */
    public function isLive(): bool
    {
        return $this->source !== null && array_key_exists($this->source, self::SOURCES);
    }

    public function sourceLabel(): ?string
    {
        return self::SOURCES[$this->source] ?? null;
    }

    /**
     * What to print.
     *
     * A live row is counted now; everything else prints what was typed.
     *
     * The fallback matters more than it looks: a source that cannot be counted
     * on this deployment - a table that was never migrated, a count that
     * throws - falls back to `value` and then to a dash. A trust number that
     * renders as a stack trace is the worst possible outcome on the one page
     * strangers see.
     */
    public function display(): string
    {
        if ($this->isLive()) {
            $counted = $this->count();

            if ($counted !== null) {
                return number_format($counted);
            }
        }

        return (string) ($this->value ?: '—');
    }

    /**
     * The real figure, or null when it cannot be had.
     *
     * Each table is checked for existence first. This runs on the public page,
     * where an exception is a 500 for every visitor, so "I could not count
     * that" has to be an answer rather than a crash.
     */
    private function count(): ?int
    {
        try {
            return match ($this->source) {
                'tenants' => $this->tally('tenants', Tenant::class),
                'shops' => $this->tally('shops', Shop::class),
                // withoutGlobalScopes: there is no signed-in user here, and
                // the count is a platform total by definition.
                'orders' => $this->tally('orders', Order::class),
                'menu_items' => $this->tally('products', Product::class),
                'modules' => count(Modules::keys()),
                'integrations' => $this->tally('integrations', Integration::class),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function tally(string $table, string $model): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return $model::query()->withoutGlobalScopes()->count();
    }
}
