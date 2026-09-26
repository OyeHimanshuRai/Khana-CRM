<?php

namespace App\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Shop;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses - money spent that is not buying stock.
 *
 * Approving is what makes it money out: a claim somebody typed in is not
 * spending until it is agreed, and only then does the payment appear in the
 * till's records.
 */
class ExpenseController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const ATTACHMENT_DIR = 'expenses';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $expenses = $this->filtered($request)
            ->with(['category:id,name', 'shop:id,name'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'expenses' => $expenses,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'categoryId' => $request->integer('category'),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => Expense::STATUSES,
            'categories' => ExpenseCategory::query()->usable()->get(),
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats($request),
            'byCategory' => $this->byCategory($request),
        ];

        return $request->header('X-Fragment')
            ? view('admin.expenses._list', $data)
            : view('admin.expenses.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        return [
            'approved' => (float) $this->filtered($request)->counted()->sum('amount'),
            'count' => $this->filtered($request)->counted()->count(),
            'pending' => (float) $this->filtered($request)->where('status', Expense::DRAFT)->sum('amount'),
            'this_month' => (float) Expense::query()
                ->counted()
                ->whereBetween('spent_on', [today()->startOfMonth(), today()])
                ->sum('amount'),
        ];
    }

    /**
     * Where the money went, for the filters applied.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function byCategory(Request $request)
    {
        /*
         | reorder() first: filtered() sorts by date, which is meaningless
         | once the rows are grouped by category - and ambiguous, since the
         | join brings a second `id` into scope.
         */
        return $this->filtered($request)
            ->counted()
            ->reorder()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorised') as category")
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('SUM(expenses.amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();
    }

    private function filtered(Request $request): Builder
    {
        return Expense::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->when($request->integer('category'), fn (Builder $q, int $id) => $q->where('expense_category_id', $id))
            // Qualified because byCategory() joins, and an unqualified `id`
            // is ambiguous the moment it does.
            ->orderByDesc('expenses.spent_on')
            ->orderByDesc('expenses.id');
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        $shop = CurrentShop::get();

        return view('admin.expenses._form', [
            'expense' => new Expense(['spent_on' => today()->toDateString(), 'method' => Payment::CASH]),
            'categories' => ExpenseCategory::query()->usable()->get(),
            'methods' => Payment::METHODS,
            'reference' => $shop ? Expense::nextReference($shop) : null,
        ]);
    }

    public function edit(Expense $expense): View
    {
        return view('admin.expenses._form', [
            'expense' => $expense,
            'categories' => ExpenseCategory::query()->usable()->get(),
            'methods' => Payment::METHODS,
            'reference' => $expense->reference,
        ]);
    }

    public function show(Expense $expense): View
    {
        return view('admin.expenses._show', ['expense' => $expense->load(['category', 'shop'])]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before recording an expense.');
        }

        $data = $this->validated($request);

        $expense = new Expense($this->attributes($data) + [
            'shop_id' => $shop->id,
            'reference' => Expense::nextReference($shop),
            'status' => Expense::DRAFT,
        ]);

        $user = Auth::user();
        $expense->forceFill([
            'created_by' => $user?->id,
            'created_by_name' => $user?->name ?? 'System',
        ]);

        if ($request->hasFile('attachment')) {
            $expense->attachment_path = $this->storeAttachment($request->file('attachment'));
        }

        $expense->save();

        ActivityLog::record(
            'expense.created',
            sprintf('Recorded expense %s · ₹%s for %s',
                $expense->reference, number_format((float) $expense->amount, 2), $expense->title),
            $expense,
        );

        return ApiResponse::success(
            "Expense {$expense->reference} recorded, awaiting approval.",
            ['id' => $expense->id, 'reference' => $expense->reference],
        );
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        if (! $expense->isEditable()) {
            return ApiResponse::error(
                'An approved or rejected expense cannot be edited. Record a correcting entry instead.'
            );
        }

        $data = $this->validated($request, $expense);

        $expense->fill($this->attributes($data));

        if ($request->hasFile('attachment')) {
            $previous = $expense->attachment_path;
            $expense->attachment_path = $this->storeAttachment($request->file('attachment'));

            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $expense->save();

        ActivityLog::record('expense.updated', "Updated expense {$expense->reference}", $expense);

        return ApiResponse::success("Expense {$expense->reference} updated.");
    }

    public function destroy(Expense $expense): JsonResponse
    {
        if (! $expense->isEditable()) {
            return ApiResponse::error('Only an unapproved expense can be deleted.');
        }

        $reference = $expense->reference;
        $attachment = $expense->attachment_path;

        $expense->delete();

        if (filled($attachment)) {
            Storage::disk('public')->delete($attachment);
        }

        ActivityLog::record('expense.deleted', "Deleted expense {$reference}");

        return ApiResponse::success("Expense {$reference} deleted.");
    }

    /* --------------------------------------------------------- decisions */

    /**
     * Agree the expense, and record the money going out.
     *
     * The payment is written here rather than at entry, so an expense
     * nobody has agreed never appears in the day's cash figures.
     */
    public function approve(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->status === Expense::APPROVED) {
            return ApiResponse::error('That expense has already been approved.');
        }

        $shop = Shop::withTrashed()->findOrFail($expense->shop_id);

        DB::transaction(function () use ($expense, $shop, $request) {
            $user = Auth::user();

            $payment = Payment::query()->create([
                'shop_id' => $shop->id,
                'number' => Payment::nextNumber($shop, Payment::OUT),
                'direction' => Payment::OUT,
                'method' => $expense->method,
                'party_name' => $expense->paid_to ?: $expense->title,
                'reference_type' => $expense::class,
                'reference_id' => $expense->id,
                'amount' => (float) $expense->amount,
                'paid_at' => $expense->spent_on,
                'transaction_ref' => $expense->transaction_ref,
                'status' => Payment::initialStatus($expense->method),
                'notes' => 'Expense '.$expense->reference.' — '.$expense->title,
            ]);

            $payment->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ])->save();

            $expense->forceFill([
                'status' => Expense::APPROVED,
                'approved_by' => $user?->id,
                'approved_by_name' => $user?->name ?? 'System',
                'approved_at' => now(),
                'review_note' => $request->string('review_note')->toString() ?: $expense->review_note,
            ])->save();
        });

        ActivityLog::record(
            'expense.approved',
            sprintf('Approved expense %s · ₹%s', $expense->reference, number_format((float) $expense->amount, 2)),
            $expense,
        );

        return ApiResponse::success(
            "Expense {$expense->reference} approved and recorded as money out."
        );
    }

    public function reject(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->status === Expense::APPROVED) {
            return ApiResponse::error(
                'An approved expense cannot be rejected. Record a correcting entry instead.'
            );
        }

        $data = $request->validate([
            'review_note' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'review_note.required' => 'Say why this is being rejected.',
        ]);

        $user = Auth::user();

        $expense->forceFill([
            'status' => Expense::REJECTED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $data['review_note'],
        ])->save();

        ActivityLog::record('expense.rejected', "Rejected expense {$expense->reference}", $expense);

        return ApiResponse::success("Expense {$expense->reference} rejected.");
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'expenses-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Reference', 'Date', 'Shop', 'Category', 'Title', 'Paid to',
            'Method', 'Reference no', 'Amount', 'Status', 'Recorded by', 'Approved by',
        ];

        $query = $this->filtered($request)->with(['category', 'shop']);

        ActivityLog::record('expense.exported', 'Exported the expense register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $expense) {
                    fputcsv($handle, [
                        $expense->reference,
                        $expense->spent_on?->format('Y-m-d'),
                        $expense->shop?->name,
                        $expense->category?->name,
                        $expense->title,
                        $expense->paid_to,
                        $expense->methodLabel(),
                        $expense->transaction_ref,
                        number_format((float) $expense->amount, 2, '.', ''),
                        $expense->statusLabel(),
                        $expense->created_by_name,
                        $expense->approved_by_name,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Expense $expense = null): array
    {
        return $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'title' => ['required', 'string', 'max:190'],
            'paid_to' => ['nullable', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'transaction_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             | An image or a PDF - a phone photograph of a bill is by far the
             | most common, and refusing PDFs would mean rejecting anything
             | emailed by a utility.
             */
            'attachment' => [
                'nullable', 'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:5120',
            ],
        ], [
            'amount.gt' => 'An expense has to be for more than zero.',
            'spent_on.before_or_equal' => 'An expense cannot be dated in the future.',
            'attachment.mimes' => 'Attach a photograph or a PDF of the bill.',
            'attachment.max' => 'The attachment must be 5 MB or smaller.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'spent_on' => $data['spent_on'],
            'title' => $data['title'],
            'paid_to' => $data['paid_to'] ?? null,
            'amount' => (float) $data['amount'],
            'method' => $data['method'],
            'transaction_ref' => $data['transaction_ref'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function storeAttachment(UploadedFile $file): string
    {
        return $file->store(self::ATTACHMENT_DIR, 'public');
    }
}
