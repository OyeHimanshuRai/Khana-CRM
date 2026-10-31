<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Page;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Site pages (privacy, terms, refund policy, about), end to end without a
 * page reload. Same shape as FaqController.
 *
 * Gated on settings.pages.*, which a Tenant Owner does not hold: these pages
 * are the platform's own and sit next to its payment forms.
 */
class PageController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'pages' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Page::count(),
                'active' => Page::where('is_active', true)->count(),
                'footer' => Page::where('is_active', true)->where('show_in_footer', true)->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.pages._list', $data)
            : view('admin.pages.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Page::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public function create(): View
    {
        return view('admin.pages._form', ['page' => new Page()]);
    }

    public function edit(Page $page): View
    {
        return view('admin.pages._form', ['page' => $page]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $page = Page::create($this->attributes($data));

        ActivityLog::record('page.created', 'Created a site page', $page, ['title' => $page->title]);

        return ApiResponse::success('Page created.', $this->payload($page));
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        $data = $this->validated($request, $page);

        $page->update($this->attributes($data, $page));

        ActivityLog::record('page.updated', 'Updated a site page', $page, ['title' => $page->title]);

        return ApiResponse::success('Page updated.', $this->payload($page));
    }

    public function destroy(Page $page): JsonResponse
    {
        $title = $page->title;

        $page->delete();

        ActivityLog::record('page.deleted', 'Deleted a site page', null, ['title' => $title]);

        return ApiResponse::success('Page deleted.');
    }

    public function toggleStatus(Page $page): JsonResponse
    {
        $active = ! $page->is_active;

        $page->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'page.activated' : 'page.deactivated',
            ($active ? 'Published' : 'Unpublished').' a site page',
            $page,
            ['title' => $page->title],
        );

        return ApiResponse::success('Page is now '.($active ? 'published' : 'hidden').'.', ['is_active' => $active]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Page $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'body' => ['required', 'string', 'max:100000'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'is_active' => ['boolean'],
            'show_in_footer' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Page $page = null): array
    {
        return [
            'title' => $data['title'],
            // A blank slug on edit keeps the address the page already has, so
            // a link somebody printed does not break when the title changes.
            'slug' => filled($data['slug'] ?? null)
                ? $data['slug']
                : ($page?->slug ?? Page::uniqueSlug($data['title'])),
            'body' => $data['body'],
            'meta_description' => $data['meta_description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'show_in_footer' => (bool) ($data['show_in_footer'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => route('page.show', $page->slug),
            'is_active' => $page->is_active,
        ];
    }
}
