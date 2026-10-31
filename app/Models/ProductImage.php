<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A gallery image on a product.
 */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'alt', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): ?string
    {
        $disk = Storage::disk('public');

        return $disk->exists($this->path) ? $disk->url($this->path) : null;
    }

    /**
     * Sweep the file when the row goes.
     *
     * On the model rather than in the controller because images are deleted
     * from several places - the gallery, a product delete, a bulk purge -
     * and an orphaned file in each of them is the same bug three times.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $image) {
            if (filled($image->path)) {
                Storage::disk('public')->delete($image->path);
            }
        });
    }
}
