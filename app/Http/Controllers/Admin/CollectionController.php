<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Collection;
use App\Models\CollectionMedia;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Collections, end to end without a page reload.
 *
 * Media is a device x position matrix rather than a fixed set of columns,
 * so every media loop walks CollectionMedia::slots() - the slots are
 * declared once, in the model.
 */
class CollectionController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const MEDIA_DIR = 'collections';

    private const MAX_IMAGE_KB = 4096;

    private const MAX_VIDEO_KB = 20480;

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'collections' => $this->filtered($request)->with('media')->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'featured' => $request->string('featured')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Collection::count(),
                'active' => Collection::where('is_active', true)->count(),
                'featured' => Collection::where('is_featured', true)->count(),
                'media' => CollectionMedia::count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.collections._list', $data)
            : view('admin.collections.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Collection::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('featured')->toString(), function (Builder $query, string $featured) {
                $query->where('is_featured', $featured === 'yes');
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
        return view('admin.collections._form', [
            'collection' => new Collection(),
            'slots' => CollectionMedia::slots(),
        ]);
    }

    public function edit(Collection $collection): View
    {
        return view('admin.collections._form', [
            'collection' => $collection->load('media'),
            'slots' => CollectionMedia::slots(),
        ]);
    }

    public function show(Collection $collection): View
    {
        return view('admin.collections._show', [
            'collection' => $collection->load('media'),
            'slots' => CollectionMedia::slots(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // validate() only returns keys that were submitted, and
        // ConvertEmptyStringsToNull blanks the rest - so a missing slug and
        // an empty one both have to fall back to the name.
        $slug = $data['slug'] ?? null;

        $collection = Collection::create([
            'name' => $data['name'],
            'slug' => Collection::uniqueSlug(filled($slug) ? $slug : $data['name']),
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
        ]);

        $this->storeMedia($request, $collection);

        ActivityLog::record('collection.created', "Created collection \"{$collection->name}\"", $collection);

        return ApiResponse::success(
            "Collection \"{$collection->name}\" created.",
            $this->payload($collection->load('media')),
        );
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $data = $this->validated($request, $collection);
        $slug = $data['slug'] ?? null;

        $collection->update([
            'name' => $data['name'],
            // Re-slugged only when the field was filled in, so an edit that
            // leaves it blank keeps the slug something may be linking to.
            'slug' => filled($slug) ? Collection::uniqueSlug($slug, $collection->id) : $collection->slug,
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
        ]);

        $this->storeMedia($request, $collection);

        ActivityLog::record('collection.updated', "Updated collection \"{$collection->name}\"", $collection);

        return ApiResponse::success(
            "Collection \"{$collection->name}\" updated.",
            $this->payload($collection->load('media')),
        );
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $name = $collection->name;
        // Read before the delete; the cascade takes the rows with it.
        $paths = $collection->media->pluck('path')->filter()->all();

        $collection->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        ActivityLog::record('collection.deleted', "Deleted collection \"{$name}\"");

        return ApiResponse::success("Collection \"{$name}\" deleted.");
    }

    public function toggleStatus(Collection $collection): JsonResponse
    {
        $active = ! $collection->is_active;

        $collection->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'collection.activated' : 'collection.deactivated',
            ($active ? 'Activated' : 'Deactivated')." collection \"{$collection->name}\"",
            $collection,
        );

        return ApiResponse::success(
            "\"{$collection->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function toggleFeatured(Collection $collection): JsonResponse
    {
        $featured = ! $collection->is_featured;

        $collection->forceFill(['is_featured' => $featured])->save();

        ActivityLog::record(
            $featured ? 'collection.featured' : 'collection.unfeatured',
            ($featured ? 'Featured' : 'Unfeatured')." collection \"{$collection->name}\"",
            $collection,
        );

        return ApiResponse::success(
            "\"{$collection->name}\" is ".($featured ? 'now featured' : 'no longer featured').'.',
            ['is_featured' => $featured],
        );
    }

    /**
     * Clear one device/position cell without touching the rest.
     */
    public function destroyMedia(Collection $collection, string $device, string $position): JsonResponse
    {
        abort_unless(array_key_exists($device, CollectionMedia::DEVICES), 404);
        abort_unless(array_key_exists($position, CollectionMedia::POSITIONS), 404);

        $piece = $collection->mediaAt($device, $position);

        if (! $piece) {
            return ApiResponse::success('There was nothing to remove.');
        }

        $path = $piece->path;
        $label = $piece->label();

        $piece->delete();
        Storage::disk('public')->delete($path);

        ActivityLog::record(
            'collection.media_removed',
            "Removed the {$label} from \"{$collection->name}\"",
            $collection,
        );

        return ApiResponse::success(ucfirst($label).' removed.');
    }

    /* ------------------------------------------------------------- media */

    /**
     * Save whatever files were submitted, one per device/position cell.
     *
     * The type is read off the file rather than typed by hand, so a slot can
     * never claim to hold a video and actually hold a JPG.
     */
    private function storeMedia(Request $request, Collection $collection): void
    {
        foreach (CollectionMedia::slots() as $slot) {
            if (! $request->hasFile($slot['field'])) {
                continue;
            }

            $file = $request->file($slot['field']);
            $existing = $collection->mediaAt($slot['device'], $slot['position']);
            $previous = $existing?->path;

            $path = $file->store(self::MEDIA_DIR, 'public');

            CollectionMedia::updateOrCreate(
                [
                    'collection_id' => $collection->id,
                    'device' => $slot['device'],
                    'position' => $slot['position'],
                ],
                [
                    'type' => self::typeOf($file),
                    'path' => $path,
                ],
            );

            // Dropped only once the replacement is on disk.
            if (filled($previous) && $previous !== $path) {
                Storage::disk('public')->delete($previous);
            }
        }

        // The relation was loaded before the writes above.
        $collection->unsetRelation('media');
    }

    /** image or video, from what was actually uploaded. */
    private static function typeOf(UploadedFile $file): string
    {
        return str_starts_with((string) $file->getMimeType(), 'video/')
            ? CollectionMedia::VIDEO
            : CollectionMedia::IMAGE;
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Collection $collection = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('collections', 'slug')->ignore($collection?->id),
            ],
            'short_description' => ['nullable', 'string', 'max:400'],
            'description' => ['nullable', 'string', 'max:20000'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ];

        $messages = ['slug.regex' => 'Use lowercase letters, numbers and hyphens only.'];

        /*
         | Every cell takes either an image or a video, so both mime lists
         | are allowed and the type is decided by what arrives. The size cap
         | still differs, which `max` alone cannot express - so the larger
         | one is the rule and the smaller is checked below.
         */
        foreach (CollectionMedia::slots() as $slot) {
            $rules[$slot['field']] = [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime',
                'max:'.self::MAX_VIDEO_KB,
            ];

            $messages[$slot['field'].'.mimes'] =
                "{$slot['label']} must be a JPG, PNG, WebP, MP4 or MOV file.";
            $messages[$slot['field'].'.mimetypes'] =
                "{$slot['label']} is not a JPG, PNG, WebP, MP4 or MOV file.";
            $messages[$slot['field'].'.max'] =
                "{$slot['label']} must be 20 MB or smaller.";
        }

        $data = $request->validate($rules, $messages);

        $this->guardImageSize($request);

        return $data;
    }

    /**
     * Images get a tighter cap than the shared `max` rule allows.
     *
     * A 20 MB ceiling makes sense for a video and none at all for a banner,
     * so the image case is checked separately.
     */
    private function guardImageSize(Request $request): void
    {
        $errors = [];

        foreach (CollectionMedia::slots() as $slot) {
            if (! $request->hasFile($slot['field'])) {
                continue;
            }

            $file = $request->file($slot['field']);

            if (self::typeOf($file) !== CollectionMedia::IMAGE) {
                continue;
            }

            if ($file->getSize() > self::MAX_IMAGE_KB * 1024) {
                $errors[$slot['field']] = [
                    "{$slot['label']}: images must be 4 MB or smaller. Use a video for anything larger.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'slug' => $collection->slug,
            'is_active' => $collection->is_active,
            'is_featured' => $collection->is_featured,
            'media' => $collection->mediaSummary(),
        ];
    }
}
