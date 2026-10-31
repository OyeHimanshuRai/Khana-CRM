<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class StorefrontSlide extends Model
{
    protected $fillable = [
        'title',
        'description',
        'button_label',
        'button_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function imageUrl(string $device = 'desktop'): ?string
    {
        $column = $device === 'mobile' ? 'mobile_image_path' : 'desktop_image_path';
        $path = $this->{$column};

        if (blank($path) && $device === 'mobile') {
            $path = $this->desktop_image_path;
        }

        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }
}
