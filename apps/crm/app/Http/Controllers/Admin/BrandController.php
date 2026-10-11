<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Brands, end to end without a page reload.
 *
 * A catalogue master, so not shop-scoped: the same brand serves every
 * branch. Same modal + AJAX-list shape as Categories.
 */
class BrandController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'brands';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $brands = $this->filtered($request)
            ->withCount('products')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'brands' => $brands,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Brand::count(),
                'active' => Brand::where('is_active', true)->count(),
                'inactive' => Brand::where('is_active', false)->count(),
                'with_logo' => Brand::whereNotNull('logo_path')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.brands._list', $data)
            : view('admin.brands.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Brand::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'name_asc' => $query->orderBy('name'),
                    'name_desc' => $query->orderByDesc('name'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    default => $query->orderBy('sort_order')->orderBy('name'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.brands._form', ['brand' => new Brand()]);
    }

    public function edit(Brand $brand): View
    {
        return view('admin.brands._form', ['brand' => $brand]);
    }

    public function show(Brand $brand): View
    {
        return view('admin.brands._show', ['brand' => $brand->loadCount('products')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $slug = $data['slug'] ?? null;

        $brand = new Brand([
            'name' => $data['name'],
            'slug' => Brand::uniqueSlug(filled($slug) ? $slug : $data['name']),
            'description' => $data['description'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'website' => $data['website'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('logo')) {
            $brand->logo_path = $this->storeLogo($request->file('logo'));
        }

        $brand->save();

        ActivityLog::record('brand.created', "Created brand \"{$brand->name}\"", $brand);

        return ApiResponse::success("Brand \"{$brand->name}\" created.", $this->payload($brand));
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $data = $this->validated($request, $brand);

        $brand->fill([
            'name' => $data['name'],
            'slug' => filled($data['slug'] ?? null)
                ? Brand::uniqueSlug($data['slug'], $brand->id)
                : $brand->slug,
            'description' => $data['description'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'website' => $data['website'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('logo')) {
            $previous = $brand->logo_path;
            $brand->logo_path = $this->storeLogo($request->file('logo'));

            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $brand->save();

        ActivityLog::record('brand.updated', "Updated brand \"{$brand->name}\"", $brand);

        return ApiResponse::success("Brand \"{$brand->name}\" updated.", $this->payload($brand));
    }

    /**
     * Remove a brand.
     *
     * Refused while products still point at it. Nulling the link instead
     * would silently strip the manufacturer off stock already on the shelf,
     * which is exactly the sort of quiet data loss an audit finds later.
     */
    public function destroy(Brand $brand): JsonResponse
    {
        $inUse = $brand->products()->withTrashed()->count();

        if ($inUse > 0) {
            return ApiResponse::error(sprintf(
                '"%s" is used by %d product%s. Move or remove those first.',
                $brand->name,
                $inUse,
                $inUse === 1 ? '' : 's',
            ));
        }

        $name = $brand->name;
        $logo = $brand->logo_path;

        $brand->delete();

        if (filled($logo)) {
            Storage::disk('public')->delete($logo);
        }

        ActivityLog::record('brand.deleted', "Deleted brand \"{$name}\"");

        return ApiResponse::success("Brand \"{$name}\" deleted.");
    }

    public function toggleStatus(Brand $brand): JsonResponse
    {
        $active = ! $brand->is_active;

        $brand->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'brand.activated' : 'brand.deactivated',
            ($active ? 'Activated' : 'Deactivated')." brand \"{$brand->name}\"",
            $brand,
        );

        return ApiResponse::success(
            "\"{$brand->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyLogo(Brand $brand): JsonResponse
    {
        if (blank($brand->logo_path)) {
            return ApiResponse::success('There was no logo to remove.', ['image' => null]);
        }

        $path = $brand->logo_path;
        $brand->forceFill(['logo_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('brand.logo_removed', "Removed the logo for \"{$brand->name}\"", $brand);

        return ApiResponse::success('Logo removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:140',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('brands', 'slug')->ignore($brand?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'website' => ['nullable', 'url', 'max:190'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            'logo' => [
                'nullable', 'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
            ],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'website.url' => 'Include the full address, starting with https://',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);
    }

    private function storeLogo(UploadedFile $file): string
    {
        return $file->store(self::IMAGE_DIR, 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'is_active' => $brand->is_active,
            'image' => $brand->logoUrl(),
        ];
    }
}
