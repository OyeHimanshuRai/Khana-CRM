<?php

namespace App\Models;

use App\Support\ScannerSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value store behind Settings > General.
 *
 * Reads go through one cached map rather than a query per key - the header,
 * footer and mail config all touch these on most requests. Any write busts
 * the cache, so a save is visible on the very next read.
 *
 * Note the map lives on map(), not all(): overriding Eloquent's all() would
 * change what every generic caller gets back.
 */
class Setting extends Model
{
    /** Keys are the primary key: strings, not auto-incrementing. */
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'settings.map';

    /**
     * Every stored setting, keyed by name.
     *
     * @return array<string, string|null>
     */
    public static function map(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('value', 'key')->all()
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::map()[$key] ?? null;

        // A stored empty string counts as unset: a blank company email should
        // fall back to the default rather than render as nothing.
        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Write a batch of settings.
     *
     * @param  array<string, string|null>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        static::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        // ScannerSettings memoises its resolved view of the scanner_* keys
        // for the request; a write here is the only thing that can make that
        // memo wrong, so it is also the only place that has to clear it.
        ScannerSettings::flush();
    }

    protected static function booted(): void
    {
        // Covers writes that bypass put() - tinker, a seeder, a stray save().
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }
}
