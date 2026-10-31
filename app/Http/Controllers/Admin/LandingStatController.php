<?php

namespace App\Http\Controllers\Admin;

use App\Models\LandingStat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Trust numbers on the landing page (§19).
 *
 * The one module here whose form is mostly a warning: a typed number is a
 * claim that goes stale, so the form offers the counted sources first. See
 * the model.
 */
class LandingStatController extends LandingContentController
{
    protected function model(): string
    {
        return LandingStat::class;
    }

    protected function views(): string
    {
        return 'admin.landing-stats';
    }

    protected function noun(): string
    {
        return 'statistic';
    }

    protected function titleOf(Model $row): string
    {
        return $row->label;
    }

    protected function extra(): array
    {
        return ['sources' => LandingStat::SOURCES];
    }

    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:90'],
            /*
             | A string, not a number: these are "1,50,000+", "24/7", "99.9%".
             | Nullable because a row with a `source` does not need one - the
             | software counts it.
             */
            'value' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', Rule::in(array_keys(LandingStat::SOURCES))],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
        ];
    }
}
