<?php

namespace App\Http\Controllers\Admin;

use App\Models\Showcase;
use Illuminate\Database\Eloquent\Model;

/**
 * Screenshots of the product (§19).
 */
class ShowcaseController extends LandingContentController
{
    protected function model(): string
    {
        return Showcase::class;
    }

    protected function views(): string
    {
        return 'admin.showcases';
    }

    protected function noun(): string
    {
        return 'screenshot';
    }

    protected function imageDir(): ?string
    {
        return Showcase::IMAGE_DIR;
    }

    protected function titleOf(Model $row): string
    {
        return $row->title;
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:250'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
            /*
             | Not `required` even on create: the row has to be saveable so the
             | image can be attached on the next edit, and a screenshot with no
             | file is simply skipped by the landing page rather than rendered
             | as a gap.
             */
            'image' => $this->imageRules(),
        ];
    }
}
