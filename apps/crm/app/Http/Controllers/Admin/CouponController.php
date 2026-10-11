<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Coupons a shop's storefront customers can redeem at checkout.
 *
 * Shop-scoped through BelongsToShop, same as CustomerController.
 */
class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $coupons = Coupon::query()
            ->search($request->string('q')->toString())
            ->when($request->filled('status'), fn (Builder $q) => $q->where('is_active', $request->string('status')->toString() === 'active'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $data = [
            'coupons' => $coupons,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.coupons._list', $data)
            : view('admin.coupons.index', $data);
    }

    public function create(): View
    {
        return view('admin.coupons._form', ['coupon' => new Coupon()]);
    }

    public function edit(Coupon $coupon): View
    {
        return view('admin.coupons._form', ['coupon' => $coupon]);
    }

    public function show(Coupon $coupon): View
    {
        return view('admin.coupons._show', ['coupon' => $coupon->loadCount('redemptions')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $coupon = Coupon::query()->create($this->attributes($data) + [
            'shop_id' => CurrentShop::idForWrite(),
        ]);

        ActivityLog::record('coupon.created', "Created coupon \"{$coupon->code}\"", $coupon);

        return ApiResponse::success("Coupon \"{$coupon->code}\" created.", $this->payload($coupon));
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validated($request, $coupon);

        $coupon->fill($this->attributes($data));
        $coupon->save();

        ActivityLog::record('coupon.updated', "Updated coupon \"{$coupon->code}\"", $coupon);

        return ApiResponse::success("Coupon \"{$coupon->code}\" updated.", $this->payload($coupon));
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $code = $coupon->code;
        $coupon->delete();

        ActivityLog::record('coupon.deleted', "Removed coupon \"{$code}\"");

        return ApiResponse::success("Coupon \"{$code}\" removed.");
    }

    public function toggleStatus(Coupon $coupon): JsonResponse
    {
        $active = ! $coupon->is_active;

        $coupon->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'coupon.activated' : 'coupon.deactivated',
            ($active ? 'Activated' : 'Deactivated')." coupon \"{$coupon->code}\"",
            $coupon,
        );

        return ApiResponse::success(
            "\"{$coupon->code}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'coupons-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Code', 'Type', 'Value', 'Min Order', 'Usage Limit', 'Used', 'Starts', 'Expires', 'Status']);

            Coupon::query()->withCount('redemptions')->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $coupon) {
                    fputcsv($handle, [
                        $coupon->code,
                        $coupon->typeLabel(),
                        $coupon->value,
                        number_format((float) $coupon->min_order_amount, 2, '.', ''),
                        $coupon->usage_limit ?? 'Unlimited',
                        $coupon->redemptions_count,
                        $coupon->starts_at?->format('Y-m-d'),
                        $coupon->expires_at?->format('Y-m-d'),
                        $coupon->is_active ? 'Active' : 'Inactive',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $shopId = $coupon?->shop_id ?? CurrentShop::idForWrite();

        return $request->validate([
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('coupons', 'code')->where('shop_id', $shopId)->whereNull('deleted_at')->ignore($coupon?->id),
            ],
            'description' => ['nullable', 'string', 'max:190'],
            'type' => ['required', Rule::in([Coupon::PERCENT, Coupon::FIXED])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'max_discount_amount' => $data['type'] === Coupon::PERCENT ? ($data['max_discount_amount'] ?? null) : null,
            'min_order_amount' => $data['min_order_amount'] ?? 0,
            'usage_limit' => $data['usage_limit'] ?? null,
            'usage_limit_per_customer' => $data['usage_limit_per_customer'] ?? 1,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'is_active' => $coupon->is_active,
        ];
    }
}
