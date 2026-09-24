<?php

namespace App\Http\Controllers\Admin;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Model;

/**
 * What customers said about the product (§19).
 *
 * See LandingContentController for the CRUD; only what makes a testimonial a
 * testimonial is here.
 */
class TestimonialController extends LandingContentController
{
    protected function model(): string
    {
        return Testimonial::class;
    }

    protected function views(): string
    {
        return 'admin.testimonials';
    }

    protected function noun(): string
    {
        return 'testimonial';
    }

    protected function imageDir(): ?string
    {
        return Testimonial::IMAGE_DIR;
    }

    protected function titleOf(Model $row): string
    {
        return $row->author_name;
    }

    protected function rules(): array
    {
        return [
            'quote' => ['required', 'string', 'max:1000'],
            // The one required attribution: an unattributed quote on a
            // marketing page is worth less than no quote at all.
            'author_name' => ['required', 'string', 'max:120'],
            'author_role' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
            'image' => $this->imageRules(),
        ];
    }
}
