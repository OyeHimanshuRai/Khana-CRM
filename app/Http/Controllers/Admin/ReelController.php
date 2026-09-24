<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Reel;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Instagram reels, end to end without a page reload.
 *
 * Same shape as the other content modules. The shortcode is pulled out of
 * the pasted URL on save, which is what the embed is built from.
 */
class ReelController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'reels';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'reels' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Reel::count(),
                'active' => Reel::where('is_active', true)->count(),
                'inactive' => Reel::where('is_active', false)->count(),
                'with_thumb' => Reel::whereNotNull('thumbnail_path')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.reels._list', $data)
            : view('admin.reels.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Reel::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'title_asc' => $query->orderBy('title'),
                    'title_desc' => $query->orderByDesc('title'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    // Nulls last, so an undated reel is not mistaken for old.
                    'published' => $query->orderByRaw('published_at IS NULL, published_at DESC'),
                    default => $query->orderBy('sort_order')->orderBy('id'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.reels._form', ['reel' => new Reel()]);
    }

    public function edit(Reel $reel): View
    {
        return view('admin.reels._form', ['reel' => $reel]);
    }

    public function show(Reel $reel): View
    {
        return view('admin.reels._show', ['reel' => $reel]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $reel = new Reel([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'reel_url' => $data['reel_url'],
            'reel_id' => Reel::shortcodeFrom($data['reel_url']),
            'sort_order' => filled($data['sort_order'] ?? null)
                ? (int) $data['sort_order']
                : Reel::nextSortOrder(),
            'published_at' => $data['published_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($request->hasFile('thumbnail')) {
            $reel->thumbnail_path = $request->file('thumbnail')->store(self::IMAGE_DIR, 'public');
        }

        $reel->save();

        ActivityLog::record('reel.created', "Created reel \"{$reel->title}\"", $reel);

        return ApiResponse::success("Reel \"{$reel->title}\" created.", $this->payload($reel));
    }

    public function update(Request $request, Reel $reel): JsonResponse
    {
        $data = $this->validated($request, $reel);

        $reel->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'reel_url' => $data['reel_url'],
            'reel_id' => Reel::shortcodeFrom($data['reel_url']),
            'sort_order' => (int) ($data['sort_order'] ?? $reel->sort_order),
            'published_at' => $data['published_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        if ($request->hasFile('thumbnail')) {
            $previous = $reel->thumbnail_path;
            $reel->thumbnail_path = $request->file('thumbnail')->store(self::IMAGE_DIR, 'public');

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $reel->save();

        ActivityLog::record('reel.updated', "Updated reel \"{$reel->title}\"", $reel);

        return ApiResponse::success("Reel \"{$reel->title}\" updated.", $this->payload($reel));
    }

    public function destroy(Reel $reel): JsonResponse
    {
        $title = $reel->title;
        $image = $reel->thumbnail_path;

        $reel->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('reel.deleted', "Deleted reel \"{$title}\"");

        return ApiResponse::success("Reel \"{$title}\" deleted.");
    }

    public function toggleStatus(Reel $reel): JsonResponse
    {
        $active = ! $reel->is_active;

        $reel->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'reel.activated' : 'reel.deactivated',
            ($active ? 'Activated' : 'Deactivated')." reel \"{$reel->title}\"",
            $reel,
        );

        return ApiResponse::success(
            "\"{$reel->title}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyThumbnail(Reel $reel): JsonResponse
    {
        if (blank($reel->thumbnail_path)) {
            return ApiResponse::success('There was no thumbnail to remove.', ['image' => null]);
        }

        $path = $reel->thumbnail_path;
        $reel->forceFill(['thumbnail_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('reel.thumbnail_removed', "Removed the thumbnail for \"{$reel->title}\"", $reel);

        return ApiResponse::success('Thumbnail removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Reel $reel = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reel_url' => ['required', 'url', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'published_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'thumbnail' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],
        ], [
            'thumbnail.image' => 'That file is not an image.',
            'thumbnail.mimes' => 'Use a JPG, PNG or WebP image.',
            'thumbnail.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'thumbnail.max' => 'The thumbnail must be 2 MB or smaller.',
        ]);

        $this->guardShortcode($data['reel_url'], $reel);

        return $data;
    }

    /**
     * The URL has to be an Instagram media link, and a new one.
     *
     * Checked here rather than with a `unique` rule because the value being
     * compared is derived from the URL, not submitted directly.
     */
    private function guardShortcode(string $url, ?Reel $reel): void
    {
        $code = Reel::shortcodeFrom($url);

        if ($code === null) {
            throw ValidationException::withMessages([
                'reel_url' => 'That does not look like an Instagram reel link. '
                    .'Paste the full URL, e.g. https://www.instagram.com/reel/ABC123/',
            ]);
        }

        $exists = Reel::where('reel_id', $code)
            ->when($reel, fn (Builder $q) => $q->where('id', '!=', $reel->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reel_url' => 'That reel has already been added.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Reel $reel): array
    {
        return [
            'id' => $reel->id,
            'title' => $reel->title,
            'reel_id' => $reel->reel_id,
            'embed' => $reel->embedUrl(),
            'is_active' => $reel->is_active,
            'image' => $reel->thumbnailUrl(),
        ];
    }
}
