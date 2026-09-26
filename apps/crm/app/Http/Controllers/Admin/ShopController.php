<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The shop (tenant) master.
 *
 * Same modal + AJAX-list shape as the rest of the admin. What is different
 * is that this module is deliberately NOT shop-scoped: it is the screen that
 * defines the scopes, so it reads the table directly and is gated on
 * settings.shops.* instead.
 */
class ShopController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /** Where uploads live on the `public` disk. */
    private const LOGO_DIR = 'shops';

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $shops = $this->filtered($request)
            ->withCount('users')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'shops' => $shops,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            // Counted through readable(), so the tiles describe the list
            // underneath them rather than the whole platform.
            'stats' => [
                'total' => $this->readable()->count(),
                'active' => (clone $this->readable())->where('is_active', true)->count(),
                'inactive' => (clone $this->readable())->where('is_active', false)->count(),
                'users' => User::whereHas(
                    'shops',
                    fn (Builder $q) => $q->whereIn('shops.id', $this->readable()->select('id')),
                )->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.shops._list', $data)
            : view('admin.shops.index', $data);
    }

    /**
     * The branches this reader may see at all.
     *
     * ---------------------------------------------------------------------
     * Whose shops
     * ---------------------------------------------------------------------
     *
     * This module is deliberately not shop-scoped - it is the screen that
     * defines the scopes, so it reads the table directly. What it still has
     * to answer for itself is *whose company's* branches are on it, and until
     * this method existed it did not: a Tenant Owner opening Shops saw every
     * outlet on the platform, with its address, its GSTIN and its staff count.
     *
     * One row per business for a customer, every row for a Super Admin, whose
     * `accessibleIds()` is every tenant. Same rule TenantController::readable
     * follows one tier up, and for the same reason.
     */
    private function readable(): Builder
    {
        $query = Shop::query();

        if (auth()->user()?->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('tenant_id', CurrentTenant::accessibleIds() ?: [0]);
    }

    /**
     * Refuse a branch this reader may not touch.
     *
     * The list is only half of it: route-model binding will hand
     * `/admin/shops/5/edit` any row whose id is typed into the address bar.
     */
    private function authorise(Shop $shop): void
    {
        abort_unless(
            CurrentTenant::canAccess((int) $shop->tenant_id),
            403,
            'That outlet belongs to another company.',
        );
    }

    private function filtered(Request $request): Builder
    {
        return $this->readable()
            ->search($request->string('q')->toString())
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
        return view('admin.shops._form', [
            'shop' => new Shop(),
            'staff' => $this->assignableUsers(),
            'assigned' => [],
            'modules' => Modules::catalogue(),
            'enabled' => Modules::defaults(),
        ]);
    }

    public function edit(Shop $shop): View
    {
        $this->authorise($shop);

        return view('admin.shops._form', [
            'shop' => $shop,
            'staff' => $this->assignableUsers(),
            'assigned' => $shop->users()->pluck('users.id')->all(),
            'modules' => Modules::catalogue(),
            'enabled' => Modules::forShop($shop),
        ]);
    }

    public function show(Shop $shop): View
    {
        $this->authorise($shop);

        return view('admin.shops._show', [
            'shop' => $shop->loadCount('users'),
            'members' => $shop->users()->orderBy('name')->get(),
            'modules' => Modules::catalogue(),
            'enabled' => Modules::forShop($shop),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        /*
         | The plan's branch allowance (§21).
         |
         | Checked here rather than in a rule, because the limit belongs to
         | the business being added to, not to the field being filled in -
         | and the answer has to name the plan for it to be any use.
         |
         | Resolved the same way Shop::creating resolves it, so the count is
         | taken against the tenant the row would actually land in. Reading
         | CurrentTenant alone would let a Super Admin creating a branch for
         | somebody else be measured against their own company, which is no
         | company at all.
         */
        $tenantId = $data['tenant_id'] ?? CurrentTenant::id() ?? CurrentTenant::soleId();

        /*
         | A branch has to belong to somebody.
         |
         | Null happens in exactly one situation: a Super Admin in
         | All-companies mode who did not say which business this is for.
         | There is no sensible default to pick - guessing the first company
         | would file somebody's restaurant under another firm - so it asks.
         |
         | Refused here rather than allowed through with a null: a shop with
         | no tenant cannot be subscribed, is invisible to its own staff, and
         | is a great deal harder to notice than an error message.
         */
        if ($tenantId === null) {
            return ApiResponse::error(
                'Choose which company this branch belongs to. You are working across all companies, '
                .'so there is no single one to file it under.'
            );
        }

        if (! PlanAccess::canAddShop($tenantId)) {
            return ApiResponse::error(PlanAccess::refusalFor($tenantId, 'shop'));
        }

        $shop = new Shop($this->attributes($data));
        $shop->slug = Shop::uniqueSlug(filled($data['slug'] ?? null) ? $data['slug'] : $data['name']);

        if ($request->hasFile('logo')) {
            $shop->logo_path = $this->storeLogo($request->file('logo'));
        }

        $shop->save();

        $this->syncMembers($shop, $data['users'] ?? []);

        ActivityLog::record('shop.created', "Created shop \"{$shop->name}\" ({$shop->code})", $shop);

        // The new shop may be the actor's second, which changes what the
        // switcher should offer on the very next page.
        CurrentShop::forget();

        return ApiResponse::success("Shop \"{$shop->name}\" created.", $this->payload($shop));
    }

    public function update(Request $request, Shop $shop): JsonResponse
    {
        $this->authorise($shop);

        $data = $this->validated($request, $shop);

        $shop->fill($this->attributes($data, $shop));

        if (filled($data['slug'] ?? null)) {
            $shop->slug = Shop::uniqueSlug($data['slug'], $shop->id);
        }

        if ($request->hasFile('logo')) {
            $previous = $shop->logo_path;
            $shop->logo_path = $this->storeLogo($request->file('logo'));

            // Dropped only once the replacement is on disk.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $shop->save();

        if (array_key_exists('users', $data)) {
            $this->syncMembers($shop, $data['users'] ?? []);
        }

        ActivityLog::record('shop.updated', "Updated shop \"{$shop->name}\" ({$shop->code})", $shop);

        CurrentShop::forget();

        return ApiResponse::success("Shop \"{$shop->name}\" updated.", $this->payload($shop));
    }

    /**
     * Soft-delete a shop.
     *
     * Refused while it is the last one: a system with no tenant has nowhere
     * to file the next invoice, and the failure would surface much later as
     * a null shop_id rather than here as a clear message.
     */
    public function destroy(Shop $shop): JsonResponse
    {
        $this->authorise($shop);

        if (Shop::count() <= 1) {
            return ApiResponse::error('This is the only shop. Create another before removing it.');
        }

        $name = $shop->name;

        /*
         | Soft, always. Invoices, payments and ledger rows name this shop,
         | and they have to stay readable - so the row survives and the
         | switcher simply stops offering it.
         */
        $shop->forceFill(['is_active' => false])->save();
        $shop->delete();

        // Anyone parked in it needs somewhere else to land.
        User::where('current_shop_id', $shop->id)->update(['current_shop_id' => null]);

        ActivityLog::record('shop.deleted', "Removed shop \"{$name}\"");

        CurrentShop::forget();

        return ApiResponse::success("Shop \"{$name}\" removed. Its records are kept for audit.");
    }

    public function toggleStatus(Shop $shop): JsonResponse
    {
        $this->authorise($shop);

        $active = ! $shop->is_active;

        if (! $active && Shop::where('is_active', true)->count() <= 1) {
            return ApiResponse::error('At least one shop has to stay active.');
        }

        $shop->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'shop.activated' : 'shop.deactivated',
            ($active ? 'Activated' : 'Deactivated')." shop \"{$shop->name}\"",
            $shop,
        );

        CurrentShop::forget();

        return ApiResponse::success(
            "\"{$shop->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyLogo(Shop $shop): JsonResponse
    {
        if (blank($shop->logo_path)) {
            return ApiResponse::success('There was no logo to remove.', ['logo' => null]);
        }

        $path = $shop->logo_path;
        $shop->forceFill(['logo_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('shop.logo_removed', "Removed the logo for \"{$shop->name}\"", $shop);

        return ApiResponse::success('Logo removed.', ['logo' => null]);
    }

    /* ------------------------------------------------------------ members */

    /**
     * Replace the shop's staff list.
     *
     * A user losing their last shop is left with none rather than being
     * quietly moved somewhere: silently granting access to a different shop
     * would be exactly the leak the pivot exists to prevent.
     *
     * @param  array<int, int|string>  $userIds
     */
    private function syncMembers(Shop $shop, array $userIds): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique();

        // Only real admin accounts, so a stray id in the payload cannot
        // enrol something that should never see the back office.
        $valid = User::whereIn('id', $ids)->where('is_admin', true)->pluck('id');

        $shop->users()->sync($valid->all());

        // Anyone dropped from this shop and parked in it needs re-resolving;
        // CurrentShop does that on its own next request, but their stored
        // pointer should not keep naming a shop they cannot open.
        User::where('current_shop_id', $shop->id)
            ->whereNotIn('id', $valid)
            ->whereDoesntHave('shops', fn ($q) => $q->where('shops.id', $shop->id))
            ->update(['current_shop_id' => null]);
    }

    /** Admin accounts that can be put on a shop. */
    private function assignableUsers()
    {
        return User::where('is_admin', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Shop $shop = null): array
    {
        /*
         | Uppercased before the unique check so "main" cannot slip past an
         | existing "MAIN" and then collide on invoice numbers.
         |
         | It has to happen here rather than in attributes(): the rule
         | compares the request against the table, so normalising afterwards
         | leaves the check looking at a value that is never stored. On a
         | case-sensitive collation that turns a field error into a 500 from
         | the unique index.
         */
        foreach (['code', 'gstin', 'pan'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => strtoupper($request->string($field)->trim()->toString())]);
            }
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],

            /*
             | Which business this branch belongs to.
             |
             | It was missing from this list entirely, and the effect was
             | worse than a rejected field: the id was validated away, the
             | resolution below fell through to null, and a Super Admin
             | working in All-companies mode created a branch belonging to
             | nobody. An orphan shop cannot be subscribed, cannot be reached
             | by its own staff and shows up under no company - and nothing
             | said so at the time.
             |
             | Only a Super Admin may name one. For everybody else the rule
             | pins it to their own company, so a posted id from somewhere
             | else is refused here rather than being quietly ignored.
             */
            'tenant_id' => array_filter([
                'nullable', 'integer',
                Rule::exists('tenants', 'id'),
                $request->user()?->isSuperAdmin()
                    ? null
                    : Rule::in(array_filter([CurrentTenant::id() ?? CurrentTenant::soleId()])),
            ]),

            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('shops', 'code')->ignore($shop?->id),
            ],
            'slug' => [
                'nullable', 'string', 'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('shops', 'slug')->ignore($shop?->id),
            ],
            'legal_name' => ['nullable', 'string', 'max:180'],

            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],
            'pan' => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'licence_no' => ['nullable', 'string', 'max:60'],

            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],

            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'state_code' => ['nullable', 'string', 'max:4'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:90'],

            'invoice_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/_-]+$/'],
            'pos_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/_-]+$/'],

            'currency' => ['nullable', 'string', 'max:8'],

            /*
             | Where this branch's UPI money lands.
             |
             | A VPA is `handle@psp`, and the regex asks for no more than
             | that: the list of PSP suffixes changes faster than any
             | validation rule, and refusing a real @ybl because this file
             | has not heard of it would be worse than accepting a typo the
             | guest's own phone rejects a second later.
             |
             | Blank is a real answer. A branch that takes no UPI leaves both
             | empty and the bill screen offers no code at all.
             */
            'upi_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]{2,}@[A-Za-z][A-Za-z0-9.]{1,}$/'],
            'upi_name' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],

            'allow_negative_stock' => ['boolean'],
            'recipe_deduction' => ['nullable', 'string', Rule::in(array_keys(\App\Services\RecipeService::MOMENTS))],
            'block_expired_sale' => ['boolean'],

            /*
             | Which lines of business this branch runs. Validated against the
             | catalogue rather than accepted as free text: an unknown key
             | would sit in the column looking meaningful and gate nothing.
             */
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(Modules::keys())],
            'modules_configured' => ['boolean'],

            'requires_otp' => ['boolean'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            'users' => ['nullable', 'array'],
            'users.*' => ['integer', 'exists:users,id'],

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
            'upi_id.regex' => 'A UPI ID looks like name@bank.',
            'invoice_prefix.regex' => 'Use letters, numbers, slashes, hyphens or underscores.',
            'pos_prefix.regex' => 'Use letters, numbers, slashes, hyphens or underscores.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);
    }

    /**
     * Map validated input onto the model's columns.
     *
     * @param  array<string, mixed>  $data
     * @param  Shop|null  $shop  the row being edited, or null on a create
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Shop $shop = null): array
    {
        return [
            /*
             | Named explicitly so Shop::creating has nothing left to guess -
             | and, on an edit, falling back to the tenant the branch already
             | has rather than to null.
             |
             | The form carries no company field, so `tenant_id` is absent
             | from every edit that any screen actually posts. Mapping that
             | absence to null wrote the null straight over a correct tenant,
             | and Shop::creating does not fire on an update to put it back:
             | changing a branch's phone number orphaned it. An orphan cannot
             | be subscribed, is invisible to its own staff and shows up
             | under no company - all from an edit that never mentioned one.
             |
             | A Super Admin who does name a company still wins, because a
             | supplied id is taken before this fallback.
             */
            'tenant_id' => $data['tenant_id'] ?? $shop?->tenant_id,
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'legal_name' => $data['legal_name'] ?? null,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'pan' => isset($data['pan']) ? strtoupper($data['pan']) : null,
            'licence_no' => $data['licence_no'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'country' => $data['country'] ?? 'India',
            'invoice_prefix' => strtoupper($data['invoice_prefix'] ?? 'INV'),
            'pos_prefix' => strtoupper($data['pos_prefix'] ?? 'POS'),
            'currency' => strtoupper($data['currency'] ?? 'INR'),
            // Left exactly as typed. A VPA is case-insensitive at every PSP
            // that matters, but it is also the string a guest reads back off
            // their phone to check, and one that does not match what the
            // manager entered reads as the wrong shop.
            'upi_id' => $data['upi_id'] ?? null,
            'upi_name' => $data['upi_name'] ?? null,
            'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
            'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
            'recipe_deduction' => $data['recipe_deduction'] ?? \App\Services\RecipeService::ON_READY,
            'block_expired_sale' => (bool) ($data['block_expired_sale'] ?? true),
            /*
             | Only written when the form actually asked.
             |
             | An unticked checkbox posts nothing, so "modules is absent" and
             | "every module was switched off" look identical in the payload -
             | and getting that wrong would silently dark every line of
             | business for any caller that simply did not mention modules.
             | The form's hidden `modules_configured` flag is what tells the
             | two apart; without it the column is left exactly as it was.
             */
            ...(($data['modules_configured'] ?? false)
                ? ['modules' => Modules::sanitise((array) ($data['modules'] ?? []))]
                : []),
            // The optional OTP step on the guest journey (§3.8). Same
            // shape as block_expired_sale above: the form always renders the
            // box, so an absent key means unticked.
            'requires_otp' => (bool) ($data['requires_otp'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
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
    private function payload(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'code' => $shop->code,
            'is_active' => $shop->is_active,
            'logo' => $shop->logoUrl(),
        ];
    }
}
