<?php

namespace Khana\Kit\Ui;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /vendor/khana/{path}, reached only when the published copy is missing.
 *
 * The web server hands out public/vendor/khana itself once the files are
 * published; this answers the fresh checkout that has not published yet, and
 * a test run. It serves only the kit's own ui folder, and only file types a
 * page loads.
 */
final class AssetController
{
    private const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    public function __invoke(string $path): BinaryFileResponse
    {
        $root = realpath(Ui::path());
        $file = realpath(Ui::path($path));
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        // realpath() resolves any ../, so this also refuses a path that
        // climbs out of the ui folder.
        if ($root === false || $file === false || $type === null || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            throw new NotFoundHttpException;
        }

        return response()->file($file, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
