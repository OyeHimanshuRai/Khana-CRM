<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\StorefrontSlide;
use App\Support\CurrentShop;
use App\Support\ImageGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StorefrontSettingsController extends Controller
{
    private const MAX_SLIDES = 8;

    public function edit(): View
    {
        $shop = $this->selectedShop();

        return view('admin.storefront.edit', [
            'shop' => $shop,
            'slides' => $shop->storefrontSlides()->orderBy('sort_order')->orderBy('id')->get(),
            'maxSlides' => self::MAX_SLIDES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $shop = $this->selectedShop();
        $data = $request->validate([
            'storefront_tagline' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:90'],
            'storefront_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'storefront_footer_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $this->validateLogoDimensions($request);

        $shop->fill([
            'storefront_tagline' => $data['storefront_tagline'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'country' => $data['country'] ?? null,
        ]);

        $previousFiles = [];

        foreach ([
            'storefront_logo' => 'storefront_logo_path',
            'storefront_footer_logo' => 'storefront_footer_logo_path',
        ] as $field => $column) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $previousFiles[] = $shop->{$column};
            $shop->{$column} = $this->storeImage($request->file($field), $shop);
        }

        $shop->save();
        $this->deleteFiles($previousFiles);

        ActivityLog::record('storefront.updated', "Updated storefront settings for \"{$shop->name}\"", $shop);

        return redirect()->route('admin.storefront.edit')->with('status', 'Storefront settings saved.');
    }

    public function storeSlide(Request $request): RedirectResponse
    {
        $shop = $this->selectedShop();

        if ($shop->storefrontSlides()->count() >= self::MAX_SLIDES) {
            throw ValidationException::withMessages([
                'slide' => 'A shop can have up to '.self::MAX_SLIDES.' storefront slides.',
            ]);
        }

        $data = $this->validatedSlide($request, true);
        $slide = $shop->storefrontSlides()->make([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'button_label' => $data['button_label'] ?? null,
            'button_url' => $data['button_url'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        $slide->desktop_image_path = $this->storeImage($request->file('desktop_image'), $shop);
        $slide->mobile_image_path = $request->hasFile('mobile_image')
            ? $this->storeImage($request->file('mobile_image'), $shop)
            : null;
        $slide->save();

        ActivityLog::record('storefront.slide_created', "Added storefront slide \"{$slide->title}\"", $slide);

        return redirect()->route('admin.storefront.edit')->with('status', 'Slide added to the storefront.');
    }

    public function updateSlide(Request $request, int $slide): RedirectResponse
    {
        $shop = $this->selectedShop();
        $storefrontSlide = $shop->storefrontSlides()->whereKey($slide)->firstOrFail();
        $data = $this->validatedSlide($request);
        $previousFiles = [];

        $storefrontSlide->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'button_label' => $data['button_label'] ?? null,
            'button_url' => $data['button_url'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        foreach ([
            'desktop_image' => 'desktop_image_path',
            'mobile_image' => 'mobile_image_path',
        ] as $field => $column) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $previousFiles[] = $storefrontSlide->{$column};
            $storefrontSlide->{$column} = $this->storeImage($request->file($field), $shop);
        }

        $storefrontSlide->save();
        $this->deleteFiles($previousFiles);

        ActivityLog::record('storefront.slide_updated', "Updated storefront slide \"{$storefrontSlide->title}\"", $storefrontSlide);

        return redirect()->route('admin.storefront.edit')->with('status', 'Slide updated.');
    }

    public function destroySlide(int $slide): RedirectResponse
    {
        $storefrontSlide = $this->selectedShop()->storefrontSlides()->whereKey($slide)->firstOrFail();
        $title = $storefrontSlide->title;
        $files = [$storefrontSlide->desktop_image_path, $storefrontSlide->mobile_image_path];

        $storefrontSlide->delete();
        $this->deleteFiles($files);

        ActivityLog::record('storefront.slide_deleted', "Removed storefront slide \"{$title}\"");

        return redirect()->route('admin.storefront.edit')->with('status', 'Slide removed.');
    }

    private function selectedShop(): Shop
    {
        $shop = CurrentShop::get();

        abort_if($shop === null, 409, 'Select a single shop before managing its storefront.');

        return $shop;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSlide(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'button_label' => ['nullable', 'string', 'max:50'],
            'button_url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (blank($value)) {
                    return;
                }

                $isRelative = str_starts_with($value, '/') && ! str_starts_with($value, '//');
                $parts = parse_url($value);
                $isWebUrl = is_array($parts)
                    && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    && filter_var($value, FILTER_VALIDATE_URL);

                if (! $isRelative && ! $isWebUrl) {
                    $fail('Use an http(s) URL or a path beginning with one slash.');
                }
            }],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['nullable', 'boolean'],
            'desktop_image' => [
                $creating ? 'required' : 'nullable',
                'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
            ],
            'mobile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        ImageGuard::dimensions($request, [
            'desktop_image' => ['label' => 'Desktop slide image', 'max_width' => 5000, 'max_height' => 3500],
            'mobile_image' => ['label' => 'Mobile slide image', 'max_width' => 2500, 'max_height' => 4000],
        ]);

        return $data;
    }

    private function validateLogoDimensions(Request $request): void
    {
        ImageGuard::dimensions($request, [
            'storefront_logo' => ['label' => 'Header logo', 'max_width' => 2400, 'max_height' => 1200],
            'storefront_footer_logo' => ['label' => 'Footer logo', 'max_width' => 2400, 'max_height' => 1200],
        ]);
    }

    private function storeImage(UploadedFile $file, Shop $shop): string
    {
        return $file->store('shopfront/'.$shop->id, 'public');
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        $disk = Storage::disk('public');

        foreach (array_filter($paths) as $path) {
            $disk->delete($path);
        }
    }
}
