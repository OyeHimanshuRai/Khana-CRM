<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * The three things every landing-page content model does (§19).
 *
 * Testimonials, outlet types, integrations, screenshots and statistics are
 * five different shapes - see the migration for why they are five tables and
 * not one - but they are administered identically: a sortable list that can be
 * switched off without being deleted, most of them carrying one uploaded
 * image.
 *
 * That part is the same five times over, so it lives here once. What differs
 * between them - what a row *means* - stays on the model, which is the split
 * worth having: this trait knows nothing about quotes or logos.
 *
 * `imageUrl()` is included even on the models with no image column. It returns
 * null harmlessly there, and the alternative is a second trait whose only job
 * is to leave one method out.
 */
trait LandingContent
{
    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order the public page shows them in.
     *
     * `sort_order` first because somebody arranged it, then `id` so rows that
     * were never arranged - every row, on the day the feature ships - come out
     * oldest-first rather than in whatever order the database felt like.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the uploaded image, or null.
     *
     * Tolerant of a missing file: a row pointing at something swept off disk
     * renders as no image rather than a broken one. Every landing section is
     * written to survive that, because a marketing page with a torn image on
     * it is worse than one with a gap.
     */
    public function imageUrl(): ?string
    {
        $path = $this->image_path ?? null;

        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }
}
