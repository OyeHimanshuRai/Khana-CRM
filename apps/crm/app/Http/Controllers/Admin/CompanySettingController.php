<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\CompanySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Settings > General.
 *
 * Every field, rule and label comes from config/company_settings.php, so
 * this class never names one. Each card on the screen is its own form and
 * saves on its own, which keeps a validation failure in the Mail section
 * from throwing away edits in the SEO section.
 */
class CompanySettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings.company', [
            'sections' => CompanySettings::sections(),
            'values' => CompanySettings::values(),
            'files' => CompanySettings::fileUrls(),
            'filledSecrets' => CompanySettings::filledSecrets(),
        ]);
    }

    /**
     * Save one section.
     */
    public function update(Request $request, string $section): JsonResponse
    {
        $definition = CompanySettings::section($section);

        abort_if($definition === null, 404);

        $rules = CompanySettings::rules($section);
        $fileRules = CompanySettings::fileRules($section);

        $data = $request->validate(
            $rules + $fileRules,
            [],
            CompanySettings::attributes()
        );

        $values = [];

        foreach ($rules as $key => $_) {
            // A secret left blank means "keep the stored one", so it must not
            // be written as an empty string.
            if (CompanySettings::isSecret($key) && blank($data[$key] ?? null)) {
                continue;
            }

            $values[$key] = $data[$key] ?? null;
        }

        foreach ($fileRules as $key => $_) {
            if (! $request->hasFile($key)) {
                continue;
            }

            $values[$key] = $this->storeFile($request, $key);
        }

        if ($values !== []) {
            Setting::put($values);
        }

        ActivityLog::record(
            'settings.updated',
            'Updated the '.($definition['label'] ?? $section).' settings',
            null,
            ['section' => $section, 'keys' => array_keys($values)]
        );

        return ApiResponse::success(
            ($definition['label'] ?? 'Settings').' saved.',
            ['files' => CompanySettings::fileUrls($section)]
        );
    }

    /**
     * Clear one uploaded file and delete it from disk.
     */
    public function destroyFile(string $section, string $key): JsonResponse
    {
        abort_if(CompanySettings::section($section) === null, 404);
        abort_unless(CompanySettings::isFile($key), 404);

        $path = Setting::get($key);

        if (filled($path)) {
            Setting::put([$key => null]);
            Storage::disk('public')->delete($path);

            ActivityLog::record(
                'settings.file_removed',
                'Removed the '.(CompanySettings::field($key)['label'] ?? $key),
                null,
                ['section' => $section, 'key' => $key]
            );
        }

        return ApiResponse::success('Image removed.', ['files' => CompanySettings::fileUrls($section)]);
    }

    /**
     * Store an upload and drop whatever it replaced.
     */
    private function storeFile(Request $request, string $key): string
    {
        $previous = Setting::get($key);

        $path = $request->file($key)->store('settings', 'public');

        // Deleted only after the replacement is on disk, so a failed write
        // cannot leave the setting pointing at nothing.
        if (filled($previous) && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }
}
