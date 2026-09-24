<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Categories, end to end without a page reload.
 *
 * The listing answers a fragment for ajax-list.js; the create, edit and
 * view screens answer fragments for modal.js; every write answers the JSON
 * envelope app.js understands. A plain visit still gets a whole page, and
 * the forms still post normally, so the module works without JavaScript.
 */
class CategoryController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /** Where uploads live on the `public` disk. */
    private const IMAGE_DIR = 'categories';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $categories = $this->filtered($request)->paginate($perPage)->withQueryString();

        $data = [
            'categories' => $categories,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Category::count(),
                'active' => Category::where('is_active', true)->count(),
                'inactive' => Category::where('is_active', false)->count(),
                'with_image' => Category::whereNotNull('image_path')->count(),
            ],
        ];

        // ajax-list.js asks for just the table + pagination, so the toolbar
        // keeps its focus and its values.
        return $request->header('X-Fragment')
            ? view('admin.categories._list', $data)
            : view('admin.categories.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Category::query()
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

    /** Blank form, for the "Add category" modal. */
    public function create(): View
    {
        return view('admin.categories._form', [
            'category' => new Category(),
            'parents' => $this->parentChoices(),
            'stations' => $this->stationChoices(),
        ]);
    }

    /** Populated form, for the "Edit" modal. */
    public function edit(Category $category): View
    {
        return view('admin.categories._form', [
            'category' => $category,
            'parents' => $this->parentChoices($category),
            'stations' => $this->stationChoices(),
        ]);
    }

    /**
     * Sections this one could sit under (§8).
     *
     * Top-level only, which is what holds the card to one level of nesting:
     * "Main Course > Indian Breads" is a menu somebody can scan on a phone,
     * and "Main Course > Indian > Breads > Stuffed" is not.
     *
     * A category that already has children cannot itself be moved under
     * another - that would make grandchildren - and neither can it be its own
     * parent.
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    private function parentChoices(?Category $category = null): Collection
    {
        if ($category && $category->children()->exists()) {
            return collect();
        }

        return Category::query()
            ->roots()
            ->when($category, fn (Builder $q) => $q->whereKeyNot($category->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Kitchen stations, or nothing when the branch runs none (§9).
     *
     * Empty is a real answer: a one-room kitchen never set any up, and the
     * field is left off the form entirely rather than offered as a select
     * with one option in it.
     *
     * @return \Illuminate\Support\Collection<int, KitchenStation>
     */
    private function stationChoices(): Collection
    {
        return KitchenStation::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    /** Read-only detail, for the "View" modal. */
    public function show(Category $category): View
    {
        return view('admin.categories._show', ['category' => $category]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // validate() only returns keys that were actually submitted, and
        // ConvertEmptyStringsToNull turns a blank one into null - so a
        // missing slug and an empty slug both have to land on the name.
        $slug = $data['slug'] ?? null;

        $category = new Category([
            'name' => $data['name'],
            'slug' => Category::uniqueSlug(filled($slug) ? $slug : $data['name']),
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'kitchen_station_id' => $data['kitchen_station_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('image')) {
            $category->image_path = $this->storeImage($request->file('image'));
        }

        $category->save();

        ActivityLog::record('category.created', "Created category \"{$category->name}\"", $category);

        return ApiResponse::success("Category \"{$category->name}\" created.", $this->payload($category));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validated($request, $category);

        $category->fill([
            'name' => $data['name'],
            // Re-slugged only when the field was actually filled in, so an
            // edit that leaves it blank keeps the slug something may link to.
            'slug' => filled($data['slug'] ?? null)
                ? Category::uniqueSlug($data['slug'], $category->id)
                : $category->slug,
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'kitchen_station_id' => $data['kitchen_station_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        if ($request->hasFile('image')) {
            $previous = $category->image_path;
            $category->image_path = $this->storeImage($request->file('image'));

            // Dropped only once the replacement is on disk, so a failed write
            // cannot leave the row pointing at nothing.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $category->save();

        ActivityLog::record('category.updated', "Updated category \"{$category->name}\"", $category);

        return ApiResponse::success("Category \"{$category->name}\" updated.", $this->payload($category));
    }

    public function destroy(Category $category): JsonResponse
    {
        $name = $category->name;
        $image = $category->image_path;

        $category->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('category.deleted', "Deleted category \"{$name}\"");

        return ApiResponse::success("Category \"{$name}\" deleted.");
    }

    /**
     * Flip active/inactive without opening the edit form.
     */
    public function toggleStatus(Category $category): JsonResponse
    {
        $active = ! $category->is_active;

        $category->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'category.activated' : 'category.deactivated',
            ($active ? 'Activated' : 'Deactivated')." category \"{$category->name}\"",
            $category,
        );

        return ApiResponse::success(
            "\"{$category->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /**
     * Remove the image and keep the category.
     */
    public function destroyImage(Category $category): JsonResponse
    {
        if (blank($category->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $category->image_path;
        $category->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('category.image_removed', "Removed the image for \"{$category->name}\"", $category);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Always optional: blank means "derive it from the name" on
            // create, and "leave the existing one alone" on edit.
            'slug' => [
                'nullable',
                'string',
                'max:140',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],

            /*
             | The parent must itself be top level, and cannot be this row.
             |
             | Both are enforced here and not only by what the form offers,
             | because a hand-posted id is how a card ends up three levels
             | deep or a category ends up its own ancestor - and the second
             | one hangs every screen that walks the tree.
             */
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('parent_id'),
                Rule::notIn(array_filter([$category?->id])),
            ],

            'kitchen_station_id' => [
                'nullable', 'integer',
                Rule::exists('kitchen_stations', 'id'),
            ],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            /*
             | Three overlapping checks on purpose:
             |   image      - it decodes as an image at all
             |   mimes      - the extension and guessed type are on the list
             |   mimetypes  - the actual reported MIME is too, so a renamed
             |                .php cannot ride in behind a .jpg extension
             | plus a size ceiling and a sane dimension range.
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
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Use a JPG, PNG or WebP image.',
            'image.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'image.max' => 'The image must be 2 MB or smaller.',
            'image.dimensions' => 'The image must be between 100×100 and 4000×4000 pixels.',
        ]);
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store(self::IMAGE_DIR, 'public');
    }

    /**
     * What the front-end needs after a write, so the modal and the row can
     * settle without a second request.
     *
     * @return array<string, mixed>
     */
    private function payload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'is_active' => $category->is_active,
            'image' => $category->imageUrl(),
        ];
    }
}
