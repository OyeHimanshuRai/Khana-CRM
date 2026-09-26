<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The company master (SRS 4, 6 and 10.1).
 *
 * One tier above shops: a tenant is the business, a shop is one of its
 * branches. Like ShopController this module is deliberately outside the
 * scopes it defines, so two things have to be enforced here by hand:
 *
 *   reading   a reader only ever sees tenants CurrentTenant says they may.
 *             For everybody except a Super Admin that is exactly one row -
 *             their own company - so this screen becomes "my company", not
 *             a directory of everyone else's.
 *
 *   writing   creating and removing a *business* is a platform action, not
 *             a customer one. A tenant owner may edit their own company's
 *             details and nothing else. Refused loudly rather than hidden,
 *             because a silently missing button reads as a broken page.
 *
 * Deleting is soft and suspension is preferred: a business's ledger,
 * invoices and stock balances outlive any billing dispute.
 */
class TenantController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /** Where uploads live on the `public` disk. */
    private const LOGO_DIR = 'tenants';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $tenants = $this->filtered($request)
            ->withCount(['shops', 'users'])
            ->paginate($perPage)
            ->withQueryString();

        $visible = $this->readable();

        $data = [
            'tenants' => $tenants,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'canCreate' => $this->isPlatformAdmin(),
            'stats' => [
                'total' => $visible->count(),
                'active' => (clone $visible)->where('is_active', true)->count(),
                'suspended' => (clone $visible)->where('is_active', false)->count(),
                'branches' => Shop::query()
                    ->whereIn('tenant_id', CurrentTenant::accessibleIds() ?: [0])
                    ->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.tenants._list', $data)
            : view('admin.tenants.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return $this->readable()
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhere('gstin', 'like', $like)
                    ->orWhere('city', 'like', $like));
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
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
        $this->authorisePlatformAdmin();

        return view('admin.tenants._form', ['tenant' => new Tenant()]);
    }

    public function edit(Tenant $tenant): View
    {
        $this->authoriseReading($tenant);

        return view('admin.tenants._form', ['tenant' => $tenant]);
    }

    public function show(Tenant $tenant): View
    {
        $this->authoriseReading($tenant);

        return view('admin.tenants._show', [
            'tenant' => $tenant->loadCount(['shops', 'users']),
            'branches' => $tenant->shops()->withCount('users')->orderBy('name')->get(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $this->authorisePlatformAdmin();

        $data = $this->validated($request);

        $tenant = new Tenant($this->attributes($data));
        $tenant->slug = Tenant::uniqueSlug(filled($data['slug'] ?? null) ? $data['slug'] : $data['name']);

        if ($request->hasFile('logo')) {
            $tenant->logo_path = $this->storeLogo($request->file('logo'));
        }

        $tenant->save();

        ActivityLog::record('tenant.created', "Created company \"{$tenant->name}\" ({$tenant->code})", $tenant);

        // The new company may be this Super Admin's second, which changes
        // what the tenant switcher should offer on the very next page.
        CurrentTenant::forget();
        CurrentShop::forget();

        return ApiResponse::success("Company \"{$tenant->name}\" created.", $this->payload($tenant));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authoriseReading($tenant);

        $data = $this->validated($request, $tenant);

        $tenant->fill($this->attributes($data));

        if (filled($data['slug'] ?? null)) {
            $tenant->slug = Tenant::uniqueSlug($data['slug'], $tenant->id);
        }

        if ($request->hasFile('logo')) {
            $previous = $tenant->logo_path;
            $tenant->logo_path = $this->storeLogo($request->file('logo'));

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $tenant->save();

        ActivityLog::record('tenant.updated', "Updated company \"{$tenant->name}\" ({$tenant->code})", $tenant);

        CurrentTenant::forget();

        return ApiResponse::success("Company \"{$tenant->name}\" updated.", $this->payload($tenant));
    }

    /**
     * Suspend or reinstate a company.
     *
     * This is the SaaS lifecycle control, and it is why `destroy` is almost
     * never the right button: a suspended tenant keeps every row it ever
     * wrote and simply cannot trade. Its staff are turned away at sign-in by
     * EnsureTenantIsActive; a Super Admin can still open it, which is the
     * only way to see why it was suspended.
     */
    public function toggleStatus(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorisePlatformAdmin();

        $active = ! $tenant->is_active;

        if (! $active && Tenant::query()->where('is_active', true)->count() <= 1) {
            return ApiResponse::error('At least one company has to stay active.');
        }

        if ($active) {
            $tenant->restore_();
        } else {
            $reason = $request->string('reason')->toString();

            $tenant->suspend($reason !== '' ? $reason : 'Suspended by '.auth()->user()->name);
        }

        ActivityLog::record(
            $active ? 'tenant.reinstated' : 'tenant.suspended',
            ($active ? 'Reinstated' : 'Suspended')." company \"{$tenant->name}\"",
            $tenant,
        );

        CurrentTenant::forget();
        CurrentShop::forget();

        return ApiResponse::success(
            "\"{$tenant->name}\" is now ".($active ? 'active' : 'suspended').'.',
            ['is_active' => $active],
        );
    }

    /**
     * Soft-delete a company.
     *
     * Refused while it still has branches. A tenant row is what every shop -
     * and through it every invoice, payment and stock movement - hangs off; taking
     * it away underneath live branches would surface much later as data that
     * belongs to nobody.
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->authorisePlatformAdmin();

        if (Tenant::query()->count() <= 1) {
            return ApiResponse::error('This is the only company. Create another before removing it.');
        }

        $branches = Shop::query()->where('tenant_id', $tenant->id)->count();

        if ($branches > 0) {
            return ApiResponse::error(
                "\"{$tenant->name}\" still has {$branches} branch(es). Move or remove them first, "
                .'or suspend the company instead — suspension keeps every record.',
            );
        }

        $name = $tenant->name;

        $tenant->forceFill(['is_active' => false])->save();
        $tenant->delete();

        // Anyone parked in it needs somewhere else to land.
        User::query()->where('current_tenant_id', $tenant->id)->update(['current_tenant_id' => null]);

        ActivityLog::record('tenant.deleted', "Removed company \"{$name}\"");

        CurrentTenant::forget();
        CurrentShop::forget();

        return ApiResponse::success("Company \"{$name}\" removed. Its records are kept for audit.");
    }

    public function destroyLogo(Tenant $tenant): JsonResponse
    {
        $this->authoriseReading($tenant);

        if (blank($tenant->logo_path)) {
            return ApiResponse::success('There was no logo to remove.', ['logo' => null]);
        }

        $path = $tenant->logo_path;
        $tenant->forceFill(['logo_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('tenant.logo_removed', "Removed the logo for \"{$tenant->name}\"", $tenant);

        return ApiResponse::success('Logo removed.', ['logo' => null]);
    }

    /* ----------------------------------------------------- authorisation */

    /**
     * The tenants this reader may see at all.
     *
     * The permission says "may they open this screen"; this says "whose
     * company". Same split as the shop pivot one tier down.
     */
    private function readable(): Builder
    {
        return Tenant::query()->whereIn('id', CurrentTenant::accessibleIds() ?: [0]);
    }

    private function isPlatformAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    private function authorisePlatformAdmin(): void
    {
        abort_unless($this->isPlatformAdmin(), 403, 'Only a Super Admin can add or remove a company.');
    }

    private function authoriseReading(Tenant $tenant): void
    {
        abort_unless(CurrentTenant::canAccess($tenant->id), 403);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Tenant $tenant = null): array
    {
        /*
         | Uppercased *before* validation, not after.
         |
         | The unique rule compares what is in the request against what is in
         | the table, and on a case-sensitive collation "acme" walks straight
         | past an existing "ACME" - then hits the database index and comes
         | back as a 500 instead of a field error. Normalising here means the
         | rule is checking the value that will actually be stored.
         */
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper($request->string('code')->trim()->toString())]);
        }

        foreach (['gstin', 'pan'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => strtoupper($request->string($field)->trim()->toString())]);
            }
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],

            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('tenants', 'code')->ignore($tenant?->id),
            ],
            'slug' => [
                'nullable', 'string', 'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('tenants', 'slug')->ignore($tenant?->id),
            ],
            'legal_name' => ['nullable', 'string', 'max:180'],

            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],
            'pan' => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],

            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],

            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'state_code' => ['nullable', 'string', 'max:4'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:90'],

            'currency' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            'logo' => [
                'nullable', 'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
            ],
        ], [
            'code.regex' => 'Use letters, numbers, hyphens or underscores; start with a letter or number.',
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'gstin.regex' => 'A GSTIN is 15 characters, digits and capital letters only.',
            'pan.regex' => 'A PAN looks like ABCDE1234F.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);
    }

    /**
     * Map validated input onto the model's columns.
     *
     * `is_active` is not taken from the form: activating and suspending a
     * company is its own audited action with its own reason, and letting an
     * ordinary save flip it would put a suspension in the log with no
     * explanation attached.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'legal_name' => $data['legal_name'] ?? null,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'pan' => isset($data['pan']) ? strtoupper($data['pan']) : null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'country' => $data['country'] ?? 'India',
            'currency' => strtoupper($data['currency'] ?? 'INR'),
            'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function storeLogo(UploadedFile $file): string
    {
        return $file->store(self::LOGO_DIR, 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'code' => $tenant->code,
            'is_active' => $tenant->is_active,
            'logo' => $tenant->logoUrl(),
        ];
    }
}
