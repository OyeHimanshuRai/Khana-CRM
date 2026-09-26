<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer master.
 *
 * Shop-scoped through BelongsToShop, so the listing and every lookup are
 * already narrowed to what the reader may see.
 *
 * Two fields are deliberately read-only here: `balance`, which the ledger
 * owns, and `opening_balance` after creation, which would silently restate
 * a customer's history if it could be edited. Both are explained on the
 * form rather than merely disabled.
 */
class CustomerController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'customers';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $customers = $this->filtered($request)
            ->with('shop:id,name,code')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'customers' => $customers,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'type' => $request->string('type')->toString(),
            'dues' => $request->string('dues')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'name_asc',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'types' => Customer::TYPES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.customers._list', $data)
            : view('admin.customers.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        return [
            'total' => Customer::count(),
            'active' => Customer::where('is_active', true)->count(),
            'with_dues' => Customer::where('balance', '>', 0)->count(),
            'outstanding' => (float) Customer::where('balance', '>', 0)->sum('balance'),
        ];
    }

    private function filtered(Request $request): Builder
    {
        return Customer::query()
            ->search($request->string('q')->toString())
            ->ofType($request->string('type')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('dues')->toString(), function (Builder $query, string $dues) {
                match ($dues) {
                    'outstanding' => $query->where('balance', '>', 0),
                    'over_limit' => $query->where('allow_credit', true)
                        ->whereColumn('balance', '>', 'credit_limit'),
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
        return view('admin.customers._form', [
            'customer' => new Customer(),
            'shops' => CurrentShop::accessible(),
            'suggestedCode' => CurrentShop::idForWrite()
                ? Customer::nextCode(CurrentShop::idForWrite())
                : null,
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('admin.customers._form', [
            'customer' => $customer,
            'shops' => CurrentShop::accessible(),
            'suggestedCode' => null,
        ]);
    }

    public function show(Customer $customer): View
    {
        return view('admin.customers._show', ['customer' => $customer->load('shop')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $shopId = (int) ($data['shop_id'] ?? CurrentShop::idForWrite());

        $customer = new Customer($this->attributes($data) + ['shop_id' => $shopId]);

        $customer->code = filled($data['code'] ?? null)
            ? $data['code']
            : Customer::nextCode($shopId);

        // An opening balance is what the customer already owed when the shop
        // started using the system, so the running balance begins there.
        $customer->balance = (float) ($data['opening_balance'] ?? 0);

        if ($request->hasFile('image')) {
            $customer->image_path = $this->storeImage($request->file('image'));
        }

        $customer->save();

        ActivityLog::record(
            'customer.created',
            "Created customer \"{$customer->name}\" ({$customer->reference()})",
            $customer,
        );

        return ApiResponse::success("Customer \"{$customer->name}\" created.", $this->payload($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $this->validated($request, $customer);

        /*
         | The shop and the opening balance are both fixed after creation.
         | Moving a customer between shops would move their invoices; editing
         | the opening balance would restate a history the ledger has already
         | been reconciled against.
         */
        $attributes = $this->attributes($data);
        unset($attributes['opening_balance']);

        $customer->fill($attributes);

        if (filled($data['code'] ?? null)) {
            $customer->code = $data['code'];
        }

        if ($request->hasFile('image')) {
            $previous = $customer->image_path;
            $customer->image_path = $this->storeImage($request->file('image'));

            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $customer->save();

        ActivityLog::record('customer.updated', "Updated customer \"{$customer->name}\"", $customer);

        return ApiResponse::success("Customer \"{$customer->name}\" updated.", $this->payload($customer));
    }

    /**
     * Remove a customer.
     *
     * Soft, and refused while they still owe money: deleting a debtor is
     * how a due quietly disappears, and the balance has to be settled or
     * written off deliberately instead.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        if ((float) $customer->balance > 0) {
            return ApiResponse::error(sprintf(
                '"%s" still owes ₹%s. Settle or write off the balance before removing them.',
                $customer->name,
                number_format((float) $customer->balance, 2),
            ));
        }

        $name = $customer->name;
        $customer->delete();

        ActivityLog::record('customer.deleted', "Removed customer \"{$name}\"");

        return ApiResponse::success("Customer \"{$name}\" removed. Their invoices are kept for audit.");
    }

    public function toggleStatus(Customer $customer): JsonResponse
    {
        $active = ! $customer->is_active;

        $customer->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'customer.activated' : 'customer.deactivated',
            ($active ? 'Activated' : 'Deactivated')." customer \"{$customer->name}\"",
            $customer,
        );

        return ApiResponse::success(
            "\"{$customer->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyImage(Customer $customer): JsonResponse
    {
        if (blank($customer->image_path)) {
            return ApiResponse::success('There was no photo to remove.', ['image' => null]);
        }

        $path = $customer->image_path;
        $customer->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('customer.image_removed', "Removed the photo for \"{$customer->name}\"", $customer);

        return ApiResponse::success('Photo removed.', ['image' => null]);
    }

    /* ------------------------------------------------------------ lookup */

    /**
     * Type-ahead for the POS and the invoice forms.
     *
     * Returns the few fields billing actually needs, including the credit
     * position - so the counter can see at a glance whether this customer
     * may be sold to on credit before the sale is half-built.
     */
    public function lookup(Request $request): JsonResponse
    {
        $term = $request->string('q')->toString();

        $customers = Customer::query()
            ->active()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get();

        return ApiResponse::success('', [
            'results' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'code' => $customer->code,
                'type' => $customer->typeLabel(),
                'balance' => (float) $customer->balance,
                'allow_credit' => $customer->allow_credit,
                'credit_limit' => (float) $customer->credit_limit,
                'available_credit' => $customer->availableCredit(),
                'gstin' => $customer->gstin,
                'state' => $customer->state,
            ])->all(),
        ]);
    }

    /* ------------------------------------------------------------ export */

    /**
     * The filtered list as CSV.
     *
     * Streamed rather than built in memory: a shop with 40,000 customers
     * should not need 40,000 rows of PHP objects to export them.
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'customers-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Code', 'Name', 'Mobile', 'Alt Mobile', 'Email', 'Type', 'GSTIN',
            'Village', 'Taluka', 'District', 'City', 'State', 'PIN',
            'Credit Allowed', 'Credit Limit', 'Credit Days', 'Balance', 'Status', 'Created',
        ];

        $query = $this->filtered($request);

        ActivityLog::record('customer.exported', 'Exported the customer list');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens UTF-8 names correctly rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $customer) {
                    fputcsv($handle, [
                        $customer->code,
                        $customer->name,
                        $customer->mobile,
                        $customer->alt_mobile,
                        $customer->email,
                        $customer->typeLabel(),
                        $customer->gstin,
                        $customer->village,
                        $customer->taluka,
                        $customer->district,
                        $customer->city,
                        $customer->state,
                        $customer->pincode,
                        $customer->allow_credit ? 'Yes' : 'No',
                        number_format((float) $customer->credit_limit, 2, '.', ''),
                        $customer->credit_days,
                        number_format((float) $customer->balance, 2, '.', ''),
                        $customer->is_active ? 'Active' : 'Inactive',
                        $customer->created_at?->format('Y-m-d'),
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
    private function validated(Request $request, ?Customer $customer = null): array
    {
        $shopId = $customer?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $customer ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],

            'name' => ['required', 'string', 'max:150'],

            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('customers', 'code')
                    ->where('shop_id', $shopId)
                    ->whereNull('deleted_at')
                    ->ignore($customer?->id),
            ],

            /*
             | The counter looks people up by phone, so a shop may not have
             | the same number twice. Still optional: a walk-in has none.
             */
            'mobile' => [
                'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/',
                Rule::unique('customers', 'mobile')
                    ->where('shop_id', $shopId)
                    ->whereNull('deleted_at')
                    ->ignore($customer?->id),
            ],
            'alt_mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],

            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'village' => ['nullable', 'string', 'max:120'],
            'taluka' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],

            'land_area' => ['nullable', 'numeric', 'between:0,99999999'],
            'primary_crops' => ['nullable', 'string', 'max:190'],

            'type' => ['required', Rule::in(array_keys(Customer::TYPES))],

            'allow_credit' => ['boolean'],
            'credit_limit' => ['nullable', 'numeric', 'between:0,9999999999999'],
            'credit_days' => ['nullable', 'integer', 'between:0,3650'],
            'opening_balance' => ['nullable', 'numeric', 'between:-9999999999999,9999999999999'],

            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],

            'image' => [
                'nullable', 'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
            ],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'shop_id.required' => 'Choose which shop this customer belongs to.',
            'mobile.unique' => 'Another customer in this shop already has that mobile number.',
            'mobile.regex' => 'Use digits, spaces, brackets, + or - only.',
            'alt_mobile.regex' => 'Use digits, spaces, brackets, + or - only.',
            'gstin.regex' => 'A GSTIN is 15 characters, digits and capital letters only.',
            'code.unique' => 'That code is already used by another customer in this shop.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $allowCredit = (bool) ($data['allow_credit'] ?? false);

        return [
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'alt_mobile' => $data['alt_mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,

            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'village' => $data['village'] ?? null,
            'taluka' => $data['taluka'] ?? null,
            'district' => $data['district'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,

            'land_area' => $data['land_area'] ?? null,
            'primary_crops' => $data['primary_crops'] ?? null,

            'type' => $data['type'],

            'allow_credit' => $allowCredit,
            // A limit on an account that cannot take credit is a trap: it
            // reads as headroom that does not exist.
            'credit_limit' => $allowCredit ? (float) ($data['credit_limit'] ?? 0) : 0,
            'credit_days' => $allowCredit ? (int) ($data['credit_days'] ?? 0) : 0,
            'opening_balance' => (float) ($data['opening_balance'] ?? 0),

            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store(self::IMAGE_DIR, 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'mobile' => $customer->mobile,
            'balance' => (float) $customer->balance,
            'is_active' => $customer->is_active,
            'image' => $customer->imageUrl(),
        ];
    }
}
