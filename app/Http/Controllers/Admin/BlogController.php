<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Blog;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Blog posts, end to end without a page reload.
 *
 * Same shape as the other content modules: a fragment for ajax-list.js,
 * fragments for modal.js, the JSON envelope for every write.
 */
class BlogController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'blogs';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'posts' => $this->filtered($request)->with('author')->paginate($perPage)->withQueryString(),
            'categories' => Blog::categories(),
            'authors' => Blog::authors(),
            'statuses' => Blog::STATUSES,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'category' => $request->string('category')->toString(),
            'author' => $request->string('author')->toString(),
            'featured' => $request->string('featured')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'newest',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Blog::count(),
                'published' => Blog::where('status', Blog::PUBLISHED)->count(),
                'draft' => Blog::where('status', Blog::DRAFT)->count(),
                'featured' => Blog::where('is_featured', true)->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.blogs._list', $data)
            : view('admin.blogs.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Blog::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('status', $status);
            })
            ->when($request->string('category')->toString(), function (Builder $query, string $category) {
                // "—" is the filter's stand-in for rows with no category.
                $category === '—'
                    ? $query->where(fn (Builder $q) => $q->whereNull('category')->orWhere('category', ''))
                    : $query->where('category', $category);
            })
            ->when($request->integer('author'), function (Builder $query, int $author) {
                $query->where('author_id', $author);
            })
            ->when($request->string('featured')->toString(), function (Builder $query, string $featured) {
                $query->where('is_featured', $featured === 'yes');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'title_asc' => $query->orderBy('title'),
                    'title_desc' => $query->orderByDesc('title'),
                    'oldest' => $query->oldest(),
                    // Nulls last, so a scheduled post is not buried.
                    'published' => $query->orderByRaw('published_at IS NULL, published_at DESC'),
                    default => $query->latest(),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.blogs._form', [
            'post' => new Blog(),
            'categories' => Blog::categories(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Blog $blog): View
    {
        return view('admin.blogs._form', [
            'post' => $blog,
            'categories' => Blog::categories(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Blog $blog): View
    {
        return view('admin.blogs._show', ['post' => $blog->load('author')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // validate() only returns keys that were submitted, and
        // ConvertEmptyStringsToNull blanks the rest - so a missing slug and
        // an empty one both have to fall back to the title.
        $slug = $data['slug'] ?? null;

        $post = new Blog([
            'title' => $data['title'],
            'slug' => Blog::uniqueSlug(filled($slug) ? $slug : $data['title']),
            'short_description' => $data['short_description'] ?? null,
            'content' => $data['content'] ?? null,
            'category' => $data['category'] ?? null,
            'tags' => Blog::parseTags($data['tags'] ?? null),
            'published_at' => $data['published_at'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'status' => $data['status'],
            'is_featured' => (bool) ($data['is_featured'] ?? false),
        ]);

        $this->applyAuthor($post, $data['author_id'] ?? null, $request);

        if ($request->hasFile('featured_image')) {
            $post->featured_image_path = $request->file('featured_image')->store(self::IMAGE_DIR, 'public');
        }

        $post->save();

        ActivityLog::record('blog.created', "Created post \"{$post->title}\"", $post);

        return ApiResponse::success("Post \"{$post->title}\" created.", $this->payload($post));
    }

    public function update(Request $request, Blog $blog): JsonResponse
    {
        $data = $this->validated($request, $blog);
        $slug = $data['slug'] ?? null;

        $blog->fill([
            'title' => $data['title'],
            // Re-slugged only when the field was filled in, so an edit that
            // leaves it blank keeps the slug something may be linking to.
            'slug' => filled($slug) ? Blog::uniqueSlug($slug, $blog->id) : $blog->slug,
            'short_description' => $data['short_description'] ?? null,
            'content' => $data['content'] ?? null,
            'category' => $data['category'] ?? null,
            'tags' => Blog::parseTags($data['tags'] ?? null),
            'published_at' => $data['published_at'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'status' => $data['status'],
            'is_featured' => (bool) ($data['is_featured'] ?? false),
        ]);

        $this->applyAuthor($blog, $data['author_id'] ?? null, $request);

        if ($request->hasFile('featured_image')) {
            $previous = $blog->featured_image_path;
            $blog->featured_image_path = $request->file('featured_image')->store(self::IMAGE_DIR, 'public');

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $blog->save();

        ActivityLog::record('blog.updated', "Updated post \"{$blog->title}\"", $blog);

        return ApiResponse::success("Post \"{$blog->title}\" updated.", $this->payload($blog));
    }

    public function destroy(Blog $blog): JsonResponse
    {
        $title = $blog->title;
        $image = $blog->featured_image_path;

        $blog->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('blog.deleted', "Deleted post \"{$title}\"");

        return ApiResponse::success("Post \"{$title}\" deleted.");
    }

    /**
     * Cycle a post through draft, published and inactive.
     *
     * A three-state field cannot be a toggle, so the request names the
     * status it wants rather than the button guessing.
     */
    public function setStatus(Request $request, Blog $blog): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Blog::STATUSES))],
        ]);

        $blog->forceFill(['status' => $data['status']])->save();

        ActivityLog::record(
            'blog.status_changed',
            "Set post \"{$blog->title}\" to ".$blog->statusLabel(),
            $blog,
        );

        return ApiResponse::success(
            "\"{$blog->title}\" is now ".strtolower($blog->statusLabel()).'.',
            ['status' => $blog->status, 'label' => $blog->statusLabel()],
        );
    }

    public function toggleFeatured(Blog $blog): JsonResponse
    {
        $featured = ! $blog->is_featured;

        $blog->forceFill(['is_featured' => $featured])->save();

        ActivityLog::record(
            $featured ? 'blog.featured' : 'blog.unfeatured',
            ($featured ? 'Featured' : 'Unfeatured')." post \"{$blog->title}\"",
            $blog,
        );

        return ApiResponse::success(
            "\"{$blog->title}\" is ".($featured ? 'now featured' : 'no longer featured').'.',
            ['is_featured' => $featured],
        );
    }

    public function destroyImage(Blog $blog): JsonResponse
    {
        if (blank($blog->featured_image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $blog->featured_image_path;
        $blog->forceFill(['featured_image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('blog.image_removed', "Removed the image for \"{$blog->title}\"", $blog);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Blog $blog = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:220',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('blogs', 'slug')->ignore($blog?->id),
            ],
            'short_description' => ['nullable', 'string', 'max:400'],
            'content' => ['nullable', 'string', 'max:100000'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'category' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'string', 'max:500'],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'status' => ['required', Rule::in(array_keys(Blog::STATUSES))],
            'is_featured' => ['boolean'],

            /*
             | Three overlapping checks: it decodes as an image, its
             | extension is on the list, and its actual reported MIME is too -
             | so a renamed .php cannot ride in behind a .jpg extension.
             */
            'featured_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=200,min_height=120,max_width=4000,max_height=4000',
            ],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'status.in' => 'Choose Draft, Published or Inactive.',
            'featured_image.image' => 'That file is not an image.',
            'featured_image.mimes' => 'Use a JPG, PNG or WebP image.',
            'featured_image.mimetypes' => 'That file is not a JPG, PNG or WebP image.',
            'featured_image.max' => 'The image must be 2 MB or smaller.',
            'featured_image.dimensions' => 'The image must be at least 200 × 120px and no larger than 4000 × 4000px.',
        ]);
    }

    /**
     * Set the author, keeping the denormalised name in step.
     *
     * The name is captured so a post stays attributable after the account
     * is deleted, which nulls author_id.
     */
    private function applyAuthor(Blog $post, mixed $authorId, Request $request): void
    {
        // Blank means "me": the person writing it is the sensible default,
        // and an unattributed post helps nobody.
        $author = filled($authorId)
            ? User::find($authorId)
            : ($post->exists ? $post->author : $request->user());

        $post->author_id = $author?->id;
        $post->author_name = $author?->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Blog $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status,
            'is_featured' => $post->is_featured,
            'image' => $post->imageUrl(),
        ];
    }
}
