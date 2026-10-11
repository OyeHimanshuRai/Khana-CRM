<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Faq;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FAQs, end to end without a page reload.
 *
 * Same shape as CategoryController and ServiceController: a fragment for
 * ajax-list.js, fragments for modal.js, and the JSON envelope for every
 * write. No image here - an FAQ is a question and an answer.
 */
class FaqController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'faqs' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'categories' => Faq::categories(),
            'search' => $request->string('q')->toString(),
            'category' => $request->string('category')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Faq::count(),
                'active' => Faq::where('is_active', true)->count(),
                'inactive' => Faq::where('is_active', false)->count(),
                'categories' => Faq::categories()->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.faqs._list', $data)
            : view('admin.faqs.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Faq::query()
            ->search($request->string('q')->toString())
            ->when($request->string('category')->toString(), function (Builder $query, string $category) {
                // "—" is the filter's stand-in for rows with no category.
                $category === '—'
                    ? $query->where(fn (Builder $q) => $q->whereNull('category')->orWhere('category', ''))
                    : $query->where('category', $category);
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'question_asc' => $query->orderBy('question'),
                    'question_desc' => $query->orderByDesc('question'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    default => $query->orderBy('category')->orderBy('sort_order')->orderBy('id'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.faqs._form', [
            'faq' => new Faq(),
            'categories' => Faq::categories(),
        ]);
    }

    public function edit(Faq $faq): View
    {
        return view('admin.faqs._form', [
            'faq' => $faq,
            'categories' => Faq::categories(),
        ]);
    }

    public function show(Faq $faq): View
    {
        return view('admin.faqs._show', ['faq' => $faq]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $faq = Faq::create([
            'question' => $data['question'],
            'answer' => $data['answer'],
            'category' => $data['category'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        ActivityLog::record('faq.created', 'Created an FAQ', $faq, ['question' => $faq->question]);

        return ApiResponse::success('FAQ created.', $this->payload($faq));
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $data = $this->validated($request);

        $faq->update([
            'question' => $data['question'],
            'answer' => $data['answer'],
            'category' => $data['category'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        ActivityLog::record('faq.updated', 'Updated an FAQ', $faq, ['question' => $faq->question]);

        return ApiResponse::success('FAQ updated.', $this->payload($faq));
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $question = $faq->question;

        $faq->delete();

        ActivityLog::record('faq.deleted', 'Deleted an FAQ', null, ['question' => $question]);

        return ApiResponse::success('FAQ deleted.');
    }

    public function toggleStatus(Faq $faq): JsonResponse
    {
        $active = ! $faq->is_active;

        $faq->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'faq.activated' : 'faq.deactivated',
            ($active ? 'Activated' : 'Deactivated').' an FAQ',
            $faq,
            ['question' => $faq->question],
        );

        return ApiResponse::success(
            'FAQ is now '.($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Faq $faq): array
    {
        return [
            'id' => $faq->id,
            'question' => $faq->question,
            'category' => $faq->category,
            'is_active' => $faq->is_active,
        ];
    }
}
