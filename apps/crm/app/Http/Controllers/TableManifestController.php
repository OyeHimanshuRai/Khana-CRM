<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TableSession;
use App\Services\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web app manifest for the guest journey (§14).
 *
 * ---------------------------------------------------------------------------
 * Generated, not a static file
 * ---------------------------------------------------------------------------
 *
 * §14 asks for "restaurant branding" on the customer web app, and a manifest
 * is the one place branding actually has to be literal: the name on the home
 * screen and the colour behind the splash both come from here. A static
 * public/manifest.json would install every restaurant on this platform as
 * whatever the file happened to say.
 *
 * So the outlet in the guest's own session names it. A guest with no sitting
 * gets the company name, which is the right fallback for somebody who reached
 * the page without scanning anything.
 */
class TableManifestController extends Controller
{
    public function __construct(private readonly TableSessionService $sessions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->session($request)?->table?->shop;

        $name = $shop?->name ?: Setting::get('company_name', config('app.name'));

        return response()->json([
            'name' => $name,
            // What fits under an icon. Twelve characters is roughly where
            // Android starts cutting it off.
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Order from your table at '.$name,

            /*
             | Opens the menu, not the site root.
             |
             | Somebody who installed this did it from a table; sending them
             | to a storefront home page on the next visit would be a strange
             | answer to "open my menu".
             */
            'start_url' => route('table.show', absolute: false),
            'scope' => '/',

            'display' => 'standalone',
            'orientation' => 'portrait',

            // Matches the theme-color in the layout; a mismatch shows as a
            // flash of the wrong colour on the splash screen.
            'theme_color' => '#f97316',
            'background_color' => '#ffffff',

            'icons' => $this->icons($shop?->logoUrl()),
        ]);
    }

    /**
     * The home-screen icons.
     *
     * The outlet's own logo where there is one. It will not be square and it
     * will not be 512 pixels, and that is fine: `purpose: any` lets the
     * browser letterbox it rather than refusing to install.
     *
     * @return array<int, array<string, string>>
     */
    private function icons(?string $logo): array
    {
        if ($logo === null) {
            return [[
                'src' => asset('assets/img/icon-512.png'),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any',
            ]];
        }

        return [[
            'src' => $logo,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ]];
    }

    private function session(Request $request): ?TableSession
    {
        $token = $request->session()->get(TableScanController::SESSION_KEY);

        return blank($token) ? null : $this->sessions->resolve($token);
    }
}
