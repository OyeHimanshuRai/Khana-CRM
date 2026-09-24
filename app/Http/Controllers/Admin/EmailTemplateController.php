<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestEmailTemplateRequest;
use App\Http\Requests\Admin\StoreEmailTemplateRequest;
use App\Http\Requests\Admin\UpdateEmailTemplateRequest;
use App\Models\ActivityLog;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Reusable email content, end to end without a page reload.
 *
 * Same fragment/modal shape as the other admin modules. The one thing worth
 * knowing: the preview renders through the same path a test send takes, so
 * what is on screen is the same HTML a recipient would get rather than a
 * second-guess at it.
 */
class EmailTemplateController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly EmailTemplateService $service)
    {
    }

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'templates' => $this->filtered($request)->with('author')->paginate($perPage)->withQueryString(),
            'categories' => EmailTemplate::categories(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'category' => $request->string('category')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'newest',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => $this->service->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.email.templates._list', $data)
            : view('admin.email.templates.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return EmailTemplate::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('category')->toString(), function (Builder $query, string $category) {
                // "—" is the filter's stand-in for rows with no category.
                $category === '—'
                    ? $query->where(fn (Builder $q) => $q->whereNull('category')->orWhere('category', ''))
                    : $query->where('category', $category);
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'oldest' => $query->oldest(),
                    'name_asc' => $query->orderBy('name'),
                    'name_desc' => $query->orderByDesc('name'),
                    'used' => $query->orderByDesc('usage_count'),
                    default => $query->latest(),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.email.templates._form', [
            'template' => new EmailTemplate(),
            'categories' => EmailTemplate::categories(),
            'mergeTags' => EmailTemplate::MERGE_TAGS,
        ]);
    }

    public function edit(EmailTemplate $template): View
    {
        Gate::authorize('update', $template);

        return view('admin.email.templates._form', [
            'template' => $template,
            'categories' => EmailTemplate::categories(),
            'mergeTags' => EmailTemplate::MERGE_TAGS,
        ]);
    }

    public function show(EmailTemplate $template): View
    {
        return view('admin.email.templates._show', [
            'template' => $template->load('author'),
            'mergeTags' => EmailTemplate::MERGE_TAGS,
        ]);
    }

    public function testForm(EmailTemplate $template): View
    {
        Gate::authorize('update', $template);

        return view('admin.email.templates._test', ['template' => $template]);
    }

    /* ----------------------------------------------------------- preview */

    /**
     * The template rendered as a finished email.
     *
     * Answered as a bare HTML document, loaded by the preview modal into a
     * sandboxed iframe. That sandbox is the point: the body is operator
     * HTML, and dropping it straight into the admin DOM would let whoever
     * wrote it run script in the session of everyone who later looks at it.
     */
    public function preview(EmailTemplate $template): Response
    {
        return response($this->service->preview($template), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Never framed by anything but our own preview modal.
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $template = new EmailTemplate($request->templateAttributes());

        $template->forceFill([
            'created_by' => $request->user()->id,
            // Captured so the row stays attributable after the account goes.
            'created_by_name' => $request->user()->name,
        ])->save();

        ActivityLog::record('email_template.created', "Created template \"{$template->name}\"", $template);

        return ApiResponse::success("Template \"{$template->name}\" created.", $this->payload($template));
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $template): JsonResponse
    {
        $template->fill($request->templateAttributes())->save();

        ActivityLog::record('email_template.updated', "Updated template \"{$template->name}\"", $template);

        return ApiResponse::success("Template \"{$template->name}\" updated.", $this->payload($template));
    }

    public function toggleStatus(EmailTemplate $template): JsonResponse
    {
        Gate::authorize('update', $template);

        $active = ! $template->is_active;

        $template->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'email_template.activated' : 'email_template.deactivated',
            ($active ? 'Activated' : 'Deactivated')." template \"{$template->name}\"",
            $template,
        );

        return ApiResponse::success(
            "\"{$template->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroy(EmailTemplate $template): JsonResponse
    {
        Gate::authorize('delete', $template);

        $name = $template->name;

        /*
         | Soft, and the campaigns that used it are untouched - template_id is
         | nullOnDelete and the content was copied, not referenced. Removing a
         | template never disturbs an email that has already gone out.
         */
        $template->delete();

        ActivityLog::record('email_template.deleted', "Deleted template \"{$name}\"");

        return ApiResponse::success("Template \"{$name}\" deleted. Campaigns that used it are unaffected.");
    }

    public function duplicate(Request $request, EmailTemplate $template): JsonResponse
    {
        Gate::authorize('create', EmailTemplate::class);

        $copy = $this->service->duplicate($template, $request->user());

        return ApiResponse::success(
            "Copied to \"{$copy->name}\". It starts inactive so it cannot be used by accident.",
            $this->payload($copy),
        );
    }

    /* ----------------------------------------------------------- sending */

    public function sendTest(SendTestEmailTemplateRequest $request, EmailTemplate $template): JsonResponse
    {
        if (blank($template->content)) {
            return ApiResponse::error('Add some content before sending a test.');
        }

        $email = $request->validated()['email'];

        return $this->service->sendTest($template, $email)
            ? ApiResponse::success("Test sent to {$email}.")
            : ApiResponse::error(
                'The test could not be sent. Check Settings > General > Mail Configuration.',
                [],
                502,
            );
    }

    /**
     * A template's content, for the campaign form's template picker.
     *
     * Kept to the three fields the picker fills in; there is no reason for
     * that screen to see anything else.
     */
    public function content(EmailTemplate $template): JsonResponse
    {
        Gate::authorize('view', $template);

        return ApiResponse::success('Template loaded.', [
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'content' => $template->content,
        ]);
    }

    /* ----------------------------------------------------------- payload */

    /**
     * @return array<string, mixed>
     */
    private function payload(EmailTemplate $template): array
    {
        return [
            'id' => $template->id,
            'uuid' => $template->uuid,
            'name' => $template->name,
            'slug' => $template->slug,
            'subject' => $template->subject,
            'is_active' => $template->is_active,
            'variables' => $template->variableList()->all(),
        ];
    }
}
