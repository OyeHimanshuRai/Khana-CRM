<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One piece of artwork for a collection: a device, a position, and a file.
 *
 * The type is recorded rather than chosen - it comes from the uploaded
 * file, so a slot labelled "video" can never hold a JPG.
 */
class CollectionMedia extends Model
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    /** Where a piece can sit, and how each reads. */
    public const DEVICES = [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
    ];

    public const POSITIONS = [
        'banner' => 'Banner',
        'thumbnail' => 'Thumbnail',
        'hover' => 'Hover',
    ];

    /** Guidance per device, mirrored by the form. */
    public const GUIDANCE = [
        'desktop' => ['max_width' => 1920, 'max_height' => 1080, 'note' => 'Up to 1920 × 1080px'],
        'mobile' => ['max_width' => 1080, 'max_height' => 1920, 'note' => 'Up to 1080 × 1920px'],
    ];

    protected $table = 'collection_media';

    protected $fillable = ['collection_id', 'type', 'device', 'position', 'path', 'sort_order'];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * Public URL, or null.
     *
     * Tolerant of a missing file: the form shows an empty slot rather than
     * a broken preview.
     */
    public function url(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->path) ? $disk->url($this->path) : null;
    }

    public function isVideo(): bool
    {
        return $this->type === self::VIDEO;
    }

    /** "Desktop banner". */
    public function label(): string
    {
        return (self::DEVICES[$this->device] ?? $this->device)
            .' '.strtolower(self::POSITIONS[$this->position] ?? $this->position);
    }

    /** The request field name this slot is submitted under. */
    public static function fieldFor(string $device, string $position): string
    {
        return "media_{$device}_{$position}";
    }

    /**
     * Every device/position pair, in the order the form lays them out.
     *
     * @return array<int, array{device: string, position: string, field: string, label: string}>
     */
    public static function slots(): array
    {
        $slots = [];

        foreach (array_keys(self::DEVICES) as $device) {
            foreach (array_keys(self::POSITIONS) as $position) {
                $slots[] = [
                    'device' => $device,
                    'position' => $position,
                    'field' => self::fieldFor($device, $position),
                    'label' => self::DEVICES[$device].' '.strtolower(self::POSITIONS[$position]),
                ];
            }
        }

        return $slots;
    }
}
