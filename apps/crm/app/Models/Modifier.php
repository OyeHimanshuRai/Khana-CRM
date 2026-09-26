<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question asked about a dish: "Choose your crust", "Add extras".
 *
 * Defined once per branch and attached to as many dishes as ask it, so
 * changing what cheese burst costs changes it on every pizza rather than on
 * one. See the migration for why min/max beats a `type` column.
 */
class Modifier extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'name', 'instruction',
        'min_select', 'max_select', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('sort_order');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('instruction', 'like', "%{$term}%")
            ->orWhereHas('options', fn (Builder $o) => $o->where('name', 'like', "%{$term}%")));
    }

    /* --------------------------------------------------------- behaviour */

    /** Whether the guest has to answer it. */
    public function isRequired(): bool
    {
        return (int) $this->min_select > 0;
    }

    /** Whether more than one option may be ticked. */
    public function isMultiple(): bool
    {
        return $this->max_select === null || (int) $this->max_select > 1;
    }

    /**
     * How the rule reads on a menu card.
     *
     * Written out rather than shown as "1-1", because the guest reading it is
     * not a developer and "Choose 1" is the only phrasing that needs no
     * explaining.
     */
    public function ruleLabel(): string
    {
        $min = (int) $this->min_select;
        $max = $this->max_select === null ? null : (int) $this->max_select;

        return match (true) {
            $min === 0 && $max === null => 'Optional — add any',
            $min === 0 && $max === 1 => 'Optional — choose 1',
            $min === 0 => "Optional — up to {$max}",
            $max !== null && $min === $max => "Choose {$min}",
            $max === null => "Choose at least {$min}",
            default => "Choose {$min} to {$max}",
        };
    }

    /**
     * Whether a set of chosen options satisfies this question.
     *
     * The single place the rule is decided, so the cart, the POS and the API
     * cannot disagree about whether a pizza has a crust.
     *
     * @param  int  $chosen  how many of this question's options were picked
     */
    public function accepts(int $chosen): bool
    {
        if ($chosen < (int) $this->min_select) {
            return false;
        }

        return $this->max_select === null || $chosen <= (int) $this->max_select;
    }
}
