<?php

namespace App\Http\Controllers\Admin;

use App\Models\OutletType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * The kinds of business the product suits (§19).
 */
class OutletTypeController extends LandingContentController
{
    protected function model(): string
    {
        return OutletType::class;
    }

    protected function views(): string
    {
        return 'admin.outlet-types';
    }

    protected function noun(): string
    {
        return 'outlet type';
    }

    protected function titleOf(Model $row): string
    {
        return $row->name;
    }

    /** The icon picker's options. */
    protected function extra(): array
    {
        return ['icons' => array_keys(config('icons', []))];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:90'],
            'blurb' => ['nullable', 'string', 'max:200'],
            /*
             | Constrained to the icon map, not merely to a string. The icon
             | component echoes its markup unescaped, so a name that is not a
             | key in config/icons.php must never reach it.
             */
            'icon' => ['required', 'string', Rule::in(array_keys(config('icons', [])))],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
        ];
    }
}
