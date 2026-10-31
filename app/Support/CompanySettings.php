<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Reads config/company_settings.php - the schema behind Settings > General.
 *
 * Everything the screen needs is derived here: the sections it renders, the
 * validation rules it enforces, which keys are files and which are secrets.
 * The controller and the view stay free of field names.
 *
 * Mirrors PermissionRegistry: one config file, many consumers.
 */
final class CompanySettings
{
    /** Field types whose value is a path on the `public` disk. */
    public const FILE_TYPES = ['image'];

    /** Field types never sent back to the browser. */
    public const SECRET_TYPES = ['secret'];

    /**
     * The whole schema, section key => definition.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sections(): array
    {
        return config('company_settings', []);
    }

    /**
     * One section, or null when the key is not a real section.
     *
     * @return array<string, mixed>|null
     */
    public static function section(string $key): ?array
    {
        return static::sections()[$key] ?? null;
    }

    /**
     * Every field across every section, key => definition.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return collect(static::sections())
            ->flatMap(fn (array $section) => $section['fields'] ?? [])
            ->all();
    }

    public static function field(string $key): ?array
    {
        return static::fields()[$key] ?? null;
    }

    public static function type(string $key): string
    {
        return static::field($key)['type'] ?? 'text';
    }

    public static function isFile(string $key): bool
    {
        return in_array(static::type($key), self::FILE_TYPES, true);
    }

    public static function isSecret(string $key): bool
    {
        return in_array(static::type($key), self::SECRET_TYPES, true);
    }

    /**
     * Validation rules for one section's non-file fields.
     *
     * The type contributes its own rule (an `email` field is validated as an
     * email whether or not the config repeats it), then the declared rules
     * are appended.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $section): array
    {
        $fields = static::section($section)['fields'] ?? [];
        $rules = [];

        foreach ($fields as $key => $field) {
            $type = $field['type'] ?? 'text';

            if (in_array($type, self::FILE_TYPES, true)) {
                continue;
            }

            $declared = $field['rules'] ?? ['nullable', 'string', 'max:255'];

            $rules[$key] = match ($type) {
                'email' => static::merge($declared, 'email'),
                'url' => static::merge($declared, 'url'),
                'number' => static::merge($declared, 'integer'),
                'select' => static::merge($declared, 'in:'.implode(',', array_keys($field['options'] ?? []))),
                // Always present: the hidden companion input (see
                // setting-field.blade.php) sends '0' for an unchecked box,
                // so there is never a genuinely missing value to be
                // "nullable" about.
                'checkbox' => $field['rules'] ?? ['required', 'in:0,1'],
                default => $declared,
            };
        }

        return $rules;
    }

    /**
     * Rules for one section's file fields.
     *
     * Always optional: an empty picker means "keep what is stored", never
     * "clear it" - clearing is what the Remove button is for.
     *
     * @return array<string, array<int, string>>
     */
    public static function fileRules(string $section): array
    {
        $fields = static::section($section)['fields'] ?? [];
        $rules = [];

        foreach ($fields as $key => $field) {
            if (! in_array($field['type'] ?? 'text', self::FILE_TYPES, true)) {
                continue;
            }

            $rules[$key] = $field['rules'] ?? [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,svg,ico',
                'max:2048',
            ];
        }

        return $rules;
    }

    /**
     * Human labels for validation messages, so an error reads "Company Name"
     * rather than "company_name".
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return collect(static::fields())
            ->map(fn (array $field) => $field['label'] ?? '')
            ->all();
    }

    /**
     * Stored values for the form, with secrets stripped.
     *
     * A field never saved falls back to its 'default', when the config
     * declares one - which is what lets a checkbox like "Enable Scanner"
     * read as on for a shop that has never opened the settings screen,
     * rather than reading as unchecked because nothing is in the database
     * yet.
     *
     * @return array<string, string|null>
     */
    public static function values(): array
    {
        $stored = Setting::map();

        return collect(static::fields())
            ->map(function (array $field, string $key) use ($stored) {
                if (in_array($field['type'] ?? 'text', self::SECRET_TYPES, true)) {
                    return null;
                }

                return $stored[$key] ?? $field['default'] ?? null;
            })
            ->all();
    }

    /**
     * Which secrets currently hold a value, so the form can say "saved"
     * without revealing what was saved.
     *
     * @return Collection<int, string>
     */
    public static function filledSecrets(): Collection
    {
        $stored = Setting::map();

        return collect(static::fields())
            ->filter(fn (array $field, string $key) => static::isSecret($key) && filled($stored[$key] ?? null))
            ->keys();
    }

    /**
     * Public URLs for the file fields, so previews can be rendered and then
     * refreshed after an upload without a page reload.
     *
     * A key whose file has gone missing resolves to null rather than a
     * broken image.
     *
     * @param  string|null  $section  Limit to one section; null for all.
     * @return array<string, string|null>
     */
    public static function fileUrls(?string $section = null): array
    {
        $disk = Storage::disk('public');
        $stored = Setting::map();

        $fields = $section === null
            ? static::fields()
            : (static::section($section)['fields'] ?? []);

        return collect($fields)
            ->filter(fn (array $field) => in_array($field['type'] ?? 'text', self::FILE_TYPES, true))
            ->map(function (array $field, string $key) use ($disk, $stored) {
                $path = $stored[$key] ?? null;

                return filled($path) && $disk->exists($path) ? $disk->url($path) : null;
            })
            ->all();
    }

    /**
     * Public URL for one file field, or null.
     *
     * The single-key version of fileUrls(), for the chrome that only wants
     * the logo and should not stat the other four on every page load.
     */
    public static function fileUrl(string $key): ?string
    {
        if (! static::isFile($key)) {
            return null;
        }

        $path = Setting::map()[$key] ?? null;

        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        // Tolerant of a file that has gone missing: the chrome falls back to
        // its lettermark rather than rendering a broken image.
        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /**
     * @param  array<int, string>  $declared
     * @return array<int, string>
     */
    private static function merge(array $declared, string $rule): array
    {
        $name = explode(':', $rule)[0];

        foreach ($declared as $existing) {
            if (is_string($existing) && explode(':', $existing)[0] === $name) {
                return $declared;
            }
        }

        return [...$declared, $rule];
    }
}
