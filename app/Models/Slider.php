<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Slider extends Model
{
    /**
     * The four media slots, and what each one accepts.
     *
     * Everything that iterates media - the form, the uploads, the delete
     * cleanup, the duplicate - walks this list, so adding a slot is one
     * entry here rather than four places to remember.
     */
    public const MEDIA = [
        /*
         | Sized for a full-bleed banner, not a thumbnail.
         |
         | The landing page draws the main_banner slides edge to edge, so an
         | 800px file - the old ceiling - was being stretched across a 1920px
         | screen and arriving soft. 2400 covers that and a 2x laptop without
         | asking anybody to upload a poster.
         */
        'desktop_image_path' => [
            'field' => 'desktop_image',
            'label' => 'Desktop Image',
            'kind' => 'image',
            'group' => 'Desktop Media',
            'accept' => 'image/svg+xml,image/png,image/jpeg',
            'formats' => 'SVG, PNG, JPG',
            'note' => 'Wide banner, around 2400 × 1200px',
            'max_width' => 2400,
            'max_height' => 1200,
        ],
        'desktop_video_path' => [
            'field' => 'desktop_video',
            'label' => 'Desktop Video',
            'kind' => 'video',
            'group' => 'Desktop Media',
            'accept' => 'video/mp4,video/quicktime',
            'formats' => 'MP4, MOV',
            'note' => 'Maximum 800 × 400px',
            'max_width' => 800,
            'max_height' => 400,
        ],
        /* The portrait crop a phone gets instead of the wide one, at the
           same 2x reasoning as the desktop slot above. */
        'mobile_image_path' => [
            'field' => 'mobile_image',
            'label' => 'Mobile Image',
            'kind' => 'image',
            'group' => 'Mobile Media',
            'accept' => 'image/svg+xml,image/png,image/jpeg',
            'formats' => 'SVG, PNG, JPG',
            'note' => 'Portrait, around 1200 × 1600px',
            'max_width' => 1200,
            'max_height' => 1600,
        ],
        'mobile_video_path' => [
            'field' => 'mobile_video',
            'label' => 'Mobile Video',
            'kind' => 'video',
            'group' => 'Mobile Media',
            'accept' => 'video/mp4,video/quicktime',
            'formats' => 'MP4, MOV',
            'note' => 'Recommended 400 × 600px',
            'max_width' => 400,
            'max_height' => 600,
        ],
    ];

    protected $fillable = [
        'title', 'description', 'item_no', 'layout',
        'redirect_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'item_no' => 'integer',
        ];
    }

    /*
     | The *_path columns are deliberately not fillable: they are only ever
     | set by the controller's upload handler, never from form input.
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
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhere('redirect_url', 'like', "%{$term}%"));
    }

    /* ------------------------------------------------------------- media */

    /**
     * Public URL for one slot, or null.
     *
     * Tolerant of a missing file: a row pointing at something swept off
     * disk shows as empty rather than a broken preview.
     */
    public function mediaUrl(string $column): ?string
    {
        $path = $this->{$column} ?? null;

        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /**
     * Every slot's URL, keyed by column.
     *
     * @return array<string, string|null>
     */
    public function mediaUrls(): array
    {
        $urls = [];

        foreach (array_keys(self::MEDIA) as $column) {
            $urls[$column] = $this->mediaUrl($column);
        }

        return $urls;
    }

    /**
     * Whatever should stand in for this slide in a list: the desktop image
     * first, then the mobile one. Videos do not make thumbnails without
     * ffmpeg, so they are not candidates.
     */
    public function thumbnailUrl(): ?string
    {
        return $this->mediaUrl('desktop_image_path') ?? $this->mediaUrl('mobile_image_path');
    }

    /** "Image", "Video", "Both" or "None" for one device column. */
    public function mediaSummary(string $device): string
    {
        $hasImage = filled($this->{$device.'_image_path'});
        $hasVideo = filled($this->{$device.'_video_path'});

        return match (true) {
            $hasImage && $hasVideo => 'Both',
            $hasVideo => 'Video',
            $hasImage => 'Image',
            default => 'None',
        };
    }

    /* ----------------------------------------------------------- layouts */

    public function layoutLabel(): string
    {
        return config('slider_layouts')[$this->layout] ?? $this->layout;
    }

    /**
     * The next free item number within a layout, so a new slide lands at
     * the end rather than colliding with an existing position.
     */
    public static function nextItemNo(string $layout): int
    {
        return (int) static::where('layout', $layout)->max('item_no') + 1;
    }
}
