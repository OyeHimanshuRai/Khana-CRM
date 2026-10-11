<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\SalesItem;
use App\Services\Consumption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Khana\Kit\Support\Ulid;

/**
 * The sales door for other billing software (contracts/api/erp.yaml).
 *
 * An outlet that bills with something else tells ERP what it sold here, in
 * the same shape CRM's own sales arrive in. The other system's reference is
 * the key: the same sale posted twice is recorded once, and the second
 * answer says so with a 200 instead of a 201.
 *
 * Lines name the other system's items. ERP makes a sales item for each the
 * first time it is seen, and it waits under "Needs recipe" like any dish.
 */
final class SaleController
{
    use ServesOutlet;

    private const CHANNELS = ['dine_in', 'takeaway', 'delivery', 'online', 'counter', 'api', 'other'];

    public function store(Request $request, Consumption $consumption): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', 'regex:'.Ulid::PATTERN],
            'reference' => ['required', 'string', 'max:100'],
            'invoice_no' => ['required', 'string', 'max:50'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_ref' => ['required', 'string', 'max:100'],
            'lines.*.variant_ref' => ['nullable', 'string', 'max:100'],
            'lines.*.name' => ['required', 'string', 'max:200'],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.net_paise' => ['required', 'integer', 'min:0'],
            'lines.*.tax_paise' => ['required', 'integer', 'min:0'],
            'totals' => ['required', 'array'],
            'totals.net_paise' => ['required', 'integer', 'min:0'],
            'totals.tax_paise' => ['required', 'integer', 'min:0'],
            'totals.round_off_paise' => ['required', 'integer', 'between:-50,50'],
            'totals.grand_paise' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'That sale is not valid.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $tenant = $this->tenantOf($request, $data['outlet_id']);

        [$sale, $made] = $consumption->saleRecorded($tenant, $data['outlet_id'], SalesItem::API, [
            'ref' => $data['reference'],
            'invoice_no' => $data['invoice_no'],
            'business_date' => $data['business_date'],
            'channel' => $data['channel'],
            'totals' => $data['totals'],
            'lines' => array_map(fn (array $line) => [
                'ref' => null,
                'item_ref' => $line['item_ref'],
                'variant_ref' => $line['variant_ref'] ?? null,
                'option_refs' => [],
                'name' => $line['name'],
                'qty' => $line['qty'],
                'net_paise' => $line['net_paise'],
                'tax_paise' => $line['tax_paise'],
                'kitchen_refs' => [],
            ], $data['lines']),
        ]);

        return response()->json($this->ack($sale), $made ? 201 : 200);
    }

    public function cancel(Request $request, Consumption $consumption, string $ref): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', 'regex:'.Ulid::PATTERN],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Say which outlet and why.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $this->tenantOf($request, $data['outlet_id']);

        $sale = $consumption->saleCancelled($data['outlet_id'], SalesItem::API, $ref, $data['reason']);

        return $sale === null
            ? response()->json(['message' => 'No sale with this reference at this outlet.'], 404)
            : response()->json($this->ack($sale));
    }

    /** @return array{sale_id: string, reference: string, status: string} */
    private function ack(Sale $sale): array
    {
        return ['sale_id' => $sale->ulid, 'reference' => $sale->ref, 'status' => $sale->status];
    }
}
