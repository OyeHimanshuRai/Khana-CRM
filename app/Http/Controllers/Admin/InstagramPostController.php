<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InstagramPost;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Instagram posts, end to end without a page reload.
 *
 * Sibling of ReelController - the two stay separate because a post and a
 * reel are different things to the front end, and merging them behind a
 * `type` column would make every query and every form say "unless it is
 * a reel" somewhere.
 */
class InstagramPostController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'instagram';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'posts' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => InstagramPost::count(),
                'active' => InstagramPost::where('is_active', true)->count(),
                'inactive' => InstagramPost::where('is_active', false)->count(),
                'with_image' => InstagramPost::whereNotNull('image_path')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.instagram._list', $data)
            : view('admin.instagram.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return InstagramPost::query()
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
                    default => $query->orderBy('sort_order')->orderBy('id'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.instagram._form', ['post' => new InstagramPost()]);
    }

    public function edit(InstagramPost $instagram): View
    {
        return view('admin.instagram._form', ['post' => $instagram]);
    }

    public function show(InstagramPost $instagram): View
    {
        return view('admin.instagram._show', ['post' => $instagram]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $post = new InstagramPost([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'post_url' => $data['post_url'],
            'post_id' => InstagramPost::shortcodeFrom($data['post_url']),
            'sort_order' => filled($data['sort_order'] ?? null)
                ? (int) $data['sort_order']
                : InstagramPost::nextSortOrder(),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($request->hasFile('image')) {
            $post->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');
        }

        $post->save();

        ActivityLog::record('instagram.created', "Created Instagram post \"{$post->title}\"", $post);

        return ApiResponse::success("Post \"{$post->title}\" created.", $this->payload($post));
    }

    public function update(Request $request, InstagramPost $instagram): JsonResponse
    {
        $data = $this->validated($request, $instagram);

        $instagram->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'post_url' => $data['post_url'],
            'post_id' => InstagramPost::shortcodeFrom($data['post_url']),
            'sort_order' => (int) ($data['sort_order'] ?? $instagram->sort_order),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        if ($request->hasFile('image')) {
            $previous = $instagram->image_path;
            $instagram->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $instagram->save();

        ActivityLog::record('instagram.updated', "Updated Instagram post \"{$instagram->title}\"", $instagram);

        return ApiResponse::success("Post \"{$instagram->title}\" updated.", $this->payload($instagram));
    }

    public function destroy(InstagramPost $instagram): JsonResponse
    {
        $title = $instagram->title;
        $image = $instagram->image_path;

        $instagram->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('instagram.deleted', "Deleted Instagram post \"{$title}\"");

        return ApiResponse::success("Post \"{$title}\" deleted.");
    }

    public function toggleStatus(InstagramPost $instagram): JsonResponse
    {
        $active = ! $instagram->is_active;

        $instagram->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'instagram.activated' : 'instagram.deactivated',
            ($active ? 'Activated' : 'Deactivated')." Instagram post \"{$instagram->title}\"",
            $instagram,
        );

        return ApiResponse::success(
            "\"{$instagram->title}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyImage(InstagramPost $instagram): JsonResponse
    {
        if (blank($instagram->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $instagram->image_path;
        $instagram->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('instagram.image_removed', "Removed the image for \"{$instagram->title}\"", $instagram);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?InstagramPost $post = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'post_url' => ['required', 'url', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['boolean'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],
        ], [
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Use a JPG, PNG or WebP image.',
            'image.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'image.max' => 'The image must be 2 MB or smaller.',
        ]);

        $this->guardShortcode($data['post_url'], $post);

        return $data;
    }

    /**
     * The URL has to be an Instagram media link, and a new one.
     *
     * Checked here rather than with a `unique` rule because the value being
     * compared is derived from the URL, not submitted directly.
     */
    private function guardShortcode(string $url, ?InstagramPost $post): void
    {
        $code = InstagramPost::shortcodeFrom($url);

        if ($code === null) {
            throw ValidationException::withMessages([
                'post_url' => 'That does not look like an Instagram post link. '
                    .'Paste the full URL, e.g. https://www.instagram.com/p/ABC123/',
            ]);
        }

        $exists = InstagramPost::where('post_id', $code)
            ->when($post, fn (Builder $q) => $q->where('id', '!=', $post->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'post_url' => 'That post has already been added.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InstagramPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'post_id' => $post->post_id,
            'embed' => $post->embedUrl(),
            'is_active' => $post->is_active,
            'image' => $post->imageUrl(),
        ];
    }
}
