<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Services, end to end without a page reload.
 *
 * Mirrors CategoryController: the listing answers a fragment for
 * ajax-list.js, the create/edit/view screens answer fragments for modal.js,
 * and every write answers the JSON envelope app.js understands. A plain
 * visit still gets a whole page, so the module works without JavaScript.
 */
class ServiceController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'services';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'services' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Service::count(),
                'active' => Service::where('is_active', true)->count(),
                'inactive' => Service::where('is_active', false)->count(),
                'priced' => Service::whereNotNull('price')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.services._list', $data)
            : view('admin.services.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Service::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'name_asc' => $query->orderBy('name'),
                    'name_desc' => $query->orderByDesc('name'),
                    'price_low' => $query->orderByRaw('price IS NULL, price ASC'),
                    'price_high' => $query->orderByRaw('price IS NULL, price DESC'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    default => $query->orderBy('sort_order')->orderBy('name'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.services._form', [
            'service' => new Service(),
            'icons' => array_keys(config('icons', [])),
        ]);
    }

    public function edit(Service $service): View
    {
        return view('admin.services._form', [
            'service' => $service,
            'icons' => array_keys(config('icons', [])),
        ]);
    }

    public function show(Service $service): View
    {
        return view('admin.services._show', ['service' => $service]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // validate() only returns keys that were submitted, and
        // ConvertEmptyStringsToNull blanks the rest - so a missing slug and
        // an empty one both have to fall back to the name.
        $slug = $data['slug'] ?? null;

        $service = new Service([
            'name' => $data['name'],
            'slug' => Service::uniqueSlug(filled($slug) ? $slug : $data['name']),
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'price' => $data['price'] ?? null,
            'price_from' => (bool) ($data['price_from'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('image')) {
            $service->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');
        }

        $service->save();

        ActivityLog::record('service.created', "Created service \"{$service->name}\"", $service);

        return ApiResponse::success("Service \"{$service->name}\" created.", $this->payload($service));
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $data = $this->validated($request, $service);
        $slug = $data['slug'] ?? null;

        $service->fill([
            'name' => $data['name'],
            // Re-slugged only when the field was filled in, so an edit that
            // leaves it blank keeps the slug something may be linking to.
            'slug' => filled($slug) ? Service::uniqueSlug($slug, $service->id) : $service->slug,
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'price' => $data['price'] ?? null,
            'price_from' => (bool) ($data['price_from'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('image')) {
            $previous = $service->image_path;
            $service->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $service->save();

        ActivityLog::record('service.updated', "Updated service \"{$service->name}\"", $service);

        return ApiResponse::success("Service \"{$service->name}\" updated.", $this->payload($service));
    }

    public function destroy(Service $service): JsonResponse
    {
        $name = $service->name;
        $image = $service->image_path;

        $service->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('service.deleted', "Deleted service \"{$name}\"");

        return ApiResponse::success("Service \"{$name}\" deleted.");
    }

    public function toggleStatus(Service $service): JsonResponse
    {
        $active = ! $service->is_active;

        $service->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'service.activated' : 'service.deactivated',
            ($active ? 'Activated' : 'Deactivated')." service \"{$service->name}\"",
            $service,
        );

        return ApiResponse::success(
            "\"{$service->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyImage(Service $service): JsonResponse
    {
        if (blank($service->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $service->image_path;
        $service->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('service.image_removed', "Removed the image for \"{$service->name}\"", $service);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Service $service = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable',
                'string',
                'max:170',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('services', 'slug')->ignore($service?->id),
            ],
            'short_description' => ['nullable', 'string', 'max:300'],
            'full_description' => ['nullable', 'string', 'max:5000'],

            // Checked against config/icons.php, so a typo cannot store a
            // name the icon component would silently render as a circle.
            'icon' => ['nullable', 'string', Rule::in(array_keys(config('icons', [])))],

            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'price_from' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            /*
             | Three overlapping checks: it decodes as an image, its
             | extension is on the list, and its actual reported MIME is too -
             | so a renamed .php cannot ride in behind a .jpg extension.
             */
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000',
            ],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'icon.in' => 'Choose an icon from the list.',
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Use a JPG, PNG or WebP image.',
            'image.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'image.max' => 'The image must be 2 MB or smaller.',
            'image.dimensions' => 'The image must be between 100×100 and 4000×4000 pixels.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'slug' => $service->slug,
            'is_active' => $service->is_active,
            'price' => $service->formattedPrice(),
            'image' => $service->imageUrl(),
        ];
    }
}
