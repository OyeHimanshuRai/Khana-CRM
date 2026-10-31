<?php

namespace App\Http\Controllers\Admin;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Model;

/**
 * What the product plugs into (§19).
 */
class IntegrationController extends LandingContentController
{
    protected function model(): string
    {
        return Integration::class;
    }

    protected function views(): string
    {
        return 'admin.integrations';
    }

    protected function noun(): string
    {
        return 'integration';
    }

    protected function imageDir(): ?string
    {
        return Integration::IMAGE_DIR;
    }

    protected function titleOf(Model $row): string
    {
        return $row->name;
    }

    /** Derived from the rows in use, the same way FAQ categories are. */
    protected function extra(): array
    {
        return ['categories' => Integration::categories()];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:90'],
            'category' => ['nullable', 'string', 'max:40'],
            'url' => ['nullable', 'url', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
            'image' => $this->imageRules(),
        ];
    }
}
