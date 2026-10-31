<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The supplier master.
 *
 * The mirror of CustomerController: shop-scoped, `balance` owned by the
 * purchase ledger rather than the form, opening balance fixed at creation.
 * Here a positive balance means the shop owes them.
 */
class SupplierController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $suppliers = $this->filtered($request)
            ->with('shop:id,name,code')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'suppliers' => $suppliers,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'payables' => $request->string('payables')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'name_asc',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'total' => Supplier::count(),
                'active' => Supplier::where('is_active', true)->count(),
                'with_payables' => Supplier::where('balance', '>', 0)->count(),
                'payable' => (float) Supplier::where('balance', '>', 0)->sum('balance'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.suppliers._list', $data)
            : view('admin.suppliers.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Supplier::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('payables')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    'outstanding' => $query->where('balance', '>', 0),
                    'clear' => $query->where('balance', '<=', 0),
                    default => null,
                };
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'name_desc' => $query->orderByDesc('name'),
                    'balance_desc' => $query->orderByDesc('balance'),
                    'balance_asc' => $query->orderBy('balance'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    default => $query->orderBy('name'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.suppliers._form', [
            'supplier' => new Supplier(),
            'shops' => CurrentShop::accessible(),
            'suggestedCode' => CurrentShop::idForWrite()
                ? Supplier::nextCode(CurrentShop::idForWrite())
                : null,
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('admin.suppliers._form', [
            'supplier' => $supplier,
            'shops' => CurrentShop::accessible(),
            'suggestedCode' => null,
        ]);
    }

    public function show(Supplier $supplier): View
    {
        return view('admin.suppliers._show', ['supplier' => $supplier->load('shop')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $shopId = (int) ($data['shop_id'] ?? CurrentShop::idForWrite());

        $supplier = new Supplier($this->attributes($data) + ['shop_id' => $shopId]);

        $supplier->code = filled($data['code'] ?? null)
            ? $data['code']
            : Supplier::nextCode($shopId);

        $supplier->balance = (float) ($data['opening_balance'] ?? 0);

        $supplier->save();

        ActivityLog::record(
            'supplier.created',
            "Created supplier \"{$supplier->displayName()}\"",
            $supplier,
        );

        return ApiResponse::success("Supplier \"{$supplier->displayName()}\" created.", $this->payload($supplier));
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validated($request, $supplier);

        // Same two fixed fields as a customer: the shop, and the opening
        // balance the purchase ledger has already been reconciled against.
        $attributes = $this->attributes($data);
        unset($attributes['opening_balance']);

        $supplier->fill($attributes);

        if (filled($data['code'] ?? null)) {
            $supplier->code = $data['code'];
        }

        $supplier->save();

        ActivityLog::record('supplier.updated', "Updated supplier \"{$supplier->displayName()}\"", $supplier);

        return ApiResponse::success("Supplier \"{$supplier->displayName()}\" updated.", $this->payload($supplier));
    }

    /**
     * Remove a supplier.
     *
     * Refused while the shop still owes them: an unpaid bill must be settled
     * or written off deliberately, not made to vanish with the payee.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        if ((float) $supplier->balance > 0) {
            return ApiResponse::error(sprintf(
                'The shop still owes "%s" ₹%s. Settle that before removing them.',
                $supplier->displayName(),
                number_format((float) $supplier->balance, 2),
            ));
        }

        $name = $supplier->displayName();
        $supplier->delete();

        ActivityLog::record('supplier.deleted', "Removed supplier \"{$name}\"");

        return ApiResponse::success("Supplier \"{$name}\" removed. Their purchase history is kept for audit.");
    }

    public function toggleStatus(Supplier $supplier): JsonResponse
    {
        $active = ! $supplier->is_active;

        $supplier->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'supplier.activated' : 'supplier.deactivated',
            ($active ? 'Activated' : 'Deactivated')." supplier \"{$supplier->displayName()}\"",
            $supplier,
        );

        return ApiResponse::success(
            "\"{$supplier->displayName()}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ------------------------------------------------------------ lookup */

    /** Type-ahead for purchase orders and goods receipts. */
    public function lookup(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->active()
            ->search($request->string('q')->toString())
            ->orderBy('name')
            ->limit(20)
            ->get();

        return ApiResponse::success('', [
            'results' => $suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->displayName(),
                'contact' => $supplier->contact_person,
                'mobile' => $supplier->mobile,
                'code' => $supplier->code,
                'gstin' => $supplier->gstin,
                'state' => $supplier->state,
                'state_code' => $supplier->state_code,
                'credit_days' => $supplier->credit_days,
                'balance' => (float) $supplier->balance,
            ])->all(),
        ]);
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'suppliers-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Code', 'Name', 'Company', 'Contact', 'Mobile', 'Email', 'GSTIN', 'PAN',
            'City', 'State', 'PIN', 'Credit Days', 'Credit Limit', 'Balance', 'Status', 'Created',
        ];

        $query = $this->filtered($request);

        ActivityLog::record('supplier.exported', 'Exported the supplier list');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $supplier) {
                    fputcsv($handle, [
                        $supplier->code,
                        $supplier->name,
                        $supplier->company,
                        $supplier->contact_person,
                        $supplier->mobile,
                        $supplier->email,
                        $supplier->gstin,
                        $supplier->pan,
                        $supplier->city,
                        $supplier->state,
                        $supplier->pincode,
                        $supplier->credit_days,
                        number_format((float) $supplier->credit_limit, 2, '.', ''),
                        number_format((float) $supplier->balance, 2, '.', ''),
                        $supplier->is_active ? 'Active' : 'Inactive',
                        $supplier->created_at?->format('Y-m-d'),
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
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $shopId = $supplier?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $supplier ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],

            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:180'],
            'contact_person' => ['nullable', 'string', 'max:120'],

            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('suppliers', 'code')
                    ->where('shop_id', $shopId)
                    ->whereNull('deleted_at')
                    ->ignore($supplier?->id),
            ],

            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'alt_mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],

            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],
            'pan' => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],

            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'state_code' => ['nullable', 'string', 'max:4'],
            'pincode' => ['nullable', 'string', 'max:12'],

            'credit_days' => ['nullable', 'integer', 'between:0,3650'],
            'credit_limit' => ['nullable', 'numeric', 'between:0,9999999999999'],
            'opening_balance' => ['nullable', 'numeric', 'between:-9999999999999,9999999999999'],

            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'bank_ifsc' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],

            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'shop_id.required' => 'Choose which shop this supplier belongs to.',
            'gstin.regex' => 'A GSTIN is 15 characters, digits and capital letters only.',
            'pan.regex' => 'A PAN looks like ABCDE1234F.',
            'bank_ifsc.regex' => 'An IFSC looks like HDFC0001234.',
            'code.unique' => 'That code is already used by another supplier in this shop.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'alt_mobile' => $data['alt_mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'pan' => isset($data['pan']) ? strtoupper($data['pan']) : null,

            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'pincode' => $data['pincode'] ?? null,

            'credit_days' => (int) ($data['credit_days'] ?? 0),
            'credit_limit' => (float) ($data['credit_limit'] ?? 0),
            'opening_balance' => (float) ($data['opening_balance'] ?? 0),

            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'bank_ifsc' => isset($data['bank_ifsc']) ? strtoupper($data['bank_ifsc']) : null,

            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->displayName(),
            'code' => $supplier->code,
            'balance' => (float) $supplier->balance,
            'is_active' => $supplier->is_active,
        ];
    }
}
