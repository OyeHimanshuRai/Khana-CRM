<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Service extends Model
{
    /** Symbols for the currencies config/company_settings.php offers. */
    private const SYMBOLS = [
        'INR' => '₹',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'د.إ',
    ];

    protected $fillable = [
        'name', 'slug', 'short_description', 'full_description',
        'icon', 'price', 'price_from', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_from' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /*
     | image_path is deliberately not fillable: the only way to set it is
     | through the controller's upload handler, never from form input.
     */

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
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('short_description', 'like', "%{$term}%")
            ->orWhere('full_description', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the uploaded image, or null.
     *
     * Tolerant of a missing file: a row pointing at something swept off disk
     * falls back to the placeholder rather than rendering a broken image.
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->image_path) ? $disk->url($this->image_path) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /**
     * "From ₹4,999" or "₹4,999", in whatever currency Settings is set to.
     *
     * Returns null rather than a zero when no price is set, so the listing
     * can show "—" instead of claiming the service is free.
     */
    public function formattedPrice(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        $code = (string) Setting::get('currency', 'INR');
        $symbol = self::SYMBOLS[$code] ?? $code.' ';

        // Trailing ".00" is noise on a price list; a real decimal is kept.
        $amount = fmod((float) $this->price, 1.0) === 0.0
            ? number_format((float) $this->price)
            : number_format((float) $this->price, 2);

        return ($this->price_from ? 'From ' : '').$symbol.$amount;
    }

    /** Falls back to a generic icon so a card never renders empty. */
    public function iconName(): string
    {
        return filled($this->icon) && array_key_exists($this->icon, config('icons', []))
            ? $this->icon
            : 'tool';
    }

    /* ------------------------------------------------------------- slugs */

    /**
     * A URL-safe slug that is not already taken.
     *
     * Appends a counter rather than failing the save, so a second "Repairs"
     * becomes "repairs-2" instead of a unique-constraint error.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'service';
        $slug = $base;
        $suffix = 2;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
