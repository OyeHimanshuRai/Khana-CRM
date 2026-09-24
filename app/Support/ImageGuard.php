<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Upload checks Laravel's own rules cannot do.
 *
 * Shared by Sliders and Events, both of which accept SVG alongside raster
 * formats and cap the pixel size per slot.
 */
final class ImageGuard
{
    /**
     * Enforce a pixel ceiling on an uploaded raster image.
     *
     * Done by hand rather than with the `dimensions` rule because SVG has no
     * raster size for getimagesize() to read - a vector is resolution
     * independent, so the limit simply does not apply to it.
     *
     * @param  array<string, array{label: string, max_width: int, max_height: int}>  $slots
     *         Keyed by request field name.
     *
     * @throws ValidationException
     */
    public static function dimensions(Request $request, array $slots): void
    {
        $errors = [];

        foreach ($slots as $field => $slot) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);

            if (self::isSvg($file)) {
                continue;
            }

            $size = @getimagesize($file->getPathname());

            // Unreadable here means the mime rules will have caught it, or
            // it is a format PHP cannot measure - not this check's problem.
            if ($size === false) {
                continue;
            }

            [$width, $height] = $size;

            if ($width > $slot['max_width'] || $height > $slot['max_height']) {
                $errors[$field] = [
                    "{$slot['label']} must be no larger than {$slot['max_width']} × {$slot['max_height']}px."
                    ." That file is {$width} × {$height}px.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Refuse an SVG carrying script.
     *
     * An SVG is a document, not a picture: served from our own origin it can
     * run JavaScript in a signed-in admin's session. Where the format list
     * asks for SVG it is accepted, but only after this check.
     *
     * Note this is a guard, not a sanitiser. If SVG uploads are not actually
     * needed, dropping svg from the mimes rules is the safer option.
     *
     * @param  array<string, array{label: string}>  $slots  Keyed by field name.
     *
     * @throws ValidationException
     */
    public static function svgContents(Request $request, array $slots): void
    {
        $errors = [];

        foreach ($slots as $field => $slot) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);

            if (! self::isSvg($file)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/<script|javascript:|on\w+\s*=|<foreignObject|<!ENTITY/i', $contents)) {
                $errors[$field] = [
                    "{$slot['label']}: that SVG contains scripting and was refused."
                    .' Export it as a plain vector, or upload a PNG.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Extension or reported type - either is enough to treat it as SVG. */
    private static function isSvg(UploadedFile $file): bool
    {
        return $file->getMimeType() === 'image/svg+xml'
            || strtolower((string) $file->getClientOriginalExtension()) === 'svg';
    }
}
