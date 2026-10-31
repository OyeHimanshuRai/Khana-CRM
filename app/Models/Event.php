<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A trade show, exhibition or in-store event.
 *
 * Note the class name shadows Illuminate\Support\Facades\Event: any file
 * that needs both must alias one of them.
 */
class Event extends Model
{
    /** What the image slot accepts - mirrored by the form and the guards. */
    public const IMAGE_MAX_WIDTH = 800;

    public const IMAGE_MAX_HEIGHT = 400;

    protected $fillable = [
        'title', 'name', 'timing', 'from_date', 'to_date', 'booth_no', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /*
     | image_path is deliberately not fillable: it is only ever set by the
     | controller's upload handler, never from form input.
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
            ->where('title', 'like', "%{$term}%")
            ->orWhere('name', 'like', "%{$term}%")
            ->orWhere('booth_no', 'like', "%{$term}%")
            ->orWhere('timing', 'like', "%{$term}%"));
    }

    /** Not finished yet: no end date, or it has not passed. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('to_date')
            ->orWhereDate('to_date', '>=', now()->toDateString()));
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the uploaded image, or null.
     *
     * Tolerant of a missing file: the listing shows a placeholder rather
     * than a broken image.
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
        return Str::of($this->title)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /* -------------------------------------------------------------- when */

    /**
     * Where this event sits relative to today.
     *
     * Separate from is_active on purpose: "Inactive" is a decision someone
     * made, "Past" is just the calendar.
     */
    public function phase(): string
    {
        $today = now()->startOfDay();

        return match (true) {
            $this->to_date !== null && $this->to_date->lt($today) => 'past',
            $this->from_date !== null && $this->from_date->gt($today) => 'upcoming',
            $this->from_date !== null || $this->to_date !== null => 'running',
            default => 'undated',
        };
    }

    public function phaseLabel(): string
    {
        return match ($this->phase()) {
            'past' => 'Past',
            'upcoming' => 'Upcoming',
            'running' => 'On now',
            default => 'No dates',
        };
    }

    public function phaseTone(): string
    {
        return match ($this->phase()) {
            'past' => 'badge',
            'upcoming' => 'badge-info',
            'running' => 'badge-success',
            default => 'badge',
        };
    }

    /** "12 – 15 Mar 2026", collapsing whatever the two dates share. */
    public function dateRange(): string
    {
        if ($this->from_date === null && $this->to_date === null) {
            return '—';
        }

        if ($this->from_date === null) {
            return 'Until '.$this->to_date->format('d M Y');
        }

        if ($this->to_date === null) {
            return 'From '.$this->from_date->format('d M Y');
        }

        if ($this->from_date->isSameDay($this->to_date)) {
            return $this->from_date->format('d M Y');
        }

        // Same month, or at least the same year - do not repeat either.
        $left = match (true) {
            $this->from_date->isSameMonth($this->to_date) => $this->from_date->format('d'),
            $this->from_date->isSameYear($this->to_date) => $this->from_date->format('d M'),
            default => $this->from_date->format('d M Y'),
        };

        return $left.' – '.$this->to_date->format('d M Y');
    }
}
