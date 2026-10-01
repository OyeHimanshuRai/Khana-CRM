<?php

namespace App\Services;

use App\Models\KitchenLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesItem;
use App\Models\SalesItemSize;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Stock\Posting;
use App\Support\Outlets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Decimal;

/**
 * Turning what was cooked and sold into stock used.
 *
 * The rules, from the architecture - each one is a method below:
 *
 *   - A kitchen line is consumed once, at the stage the outlet chose
 *     (accepted, ready by default, or served).
 *   - A bill line that settles kitchen lines books nothing more: the food
 *     was already counted when it was cooked.
 *   - A bill line that never went through the kitchen - a cola, a sale from
 *     other billing software - is consumed when the bill is recorded.
 *   - A kitchen line cancelled before cooking began gives its stock back.
 *     From "preparing" onwards the ingredients are gone: wastage.
 *   - A cancelled bill gives back only what the bill itself took. What the
 *     kitchen cooked stays used, because it was cooked.
 *   - A return puts stock back only where CRM says it can be sold again.
 *
 * A dish with no recipe uses nothing. It is recorded all the same, so the
 * sale still counts towards food cost and the dish still shows under "Needs
 * recipe" - but recipes written later do not reach back into the past.
 *
 * Every movement made here carries the id of the event and line that caused
 * it, so the ledger itself refuses a second application of the same event.
 */
class Consumption
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Recipes $recipes,
        private readonly MenuCopy $menu,
    ) {}

    /* ---------------------------------------------------------- kitchen */

    /**
     * crm.kitchen.line_advanced
     *
     * @param  array<string, mixed>  $data
     */
    public function kitchenAdvanced(string $tenantId, string $outletId, array $data, string $eventId, CarbonInterface $at): KitchenLine
    {
        return DB::transaction(function () use ($tenantId, $outletId, $data, $eventId, $at) {
            $line = $this->kitchenLine($tenantId, $outletId, $data);

            if (KitchenLine::rank($data['stage']) > KitchenLine::rank($line->stage)) {
                $line->stage = $data['stage'];
            }

            $due = KitchenLine::rank($line->stage) >= KitchenLine::rank(Outlets::settings($outletId)->consume_stage);

            if ($due && $line->consumed_at === null && $line->cancelled_at === null) {
                $line->save();
                $line->cost_paise = $this->cook($line, $line, StockMovement::CONSUMPTION, null, $eventId, $line->ref, $at);
                $line->consumed_at = $at;
                $line->business_date = Outlets::businessDate($outletId, $at);
            }

            $line->save();

            return $line;
        });
    }

    /**
     * crm.kitchen.line_cancelled
     *
     * @param  array<string, mixed>  $data
     */
    public function kitchenCancelled(string $tenantId, string $outletId, array $data, string $eventId, CarbonInterface $at): KitchenLine
    {
        return DB::transaction(function () use ($tenantId, $outletId, $data, $eventId, $at) {
            $line = $this->kitchenLine($tenantId, $outletId, $data);

            if ($line->cancelled_at !== null) {
                return $line;
            }

            $line->cancelled_at = $at;
            $line->save();

            $cookingBegan = KitchenLine::rank($data['stage_reached']) >= KitchenLine::rank('preparing');

            if ($line->consumed_at !== null && ! $cookingBegan) {
                // Booked at "accepted", cancelled before anybody lit a
                // burner: the stock never left the shelf.
                $this->stock->reverse(
                    $this->bookedBy($line),
                    new Posting(StockMovement::REVERSAL, $line, 'Cancelled before cooking: '.$data['reason'], $eventId, $line->ref,
                        Outlets::businessDate($outletId, $at), $at),
                );

                $line->consumed_at = null;
                $line->cost_paise = 0;
            } elseif ($line->consumed_at !== null) {
                // Already booked as used, and it was: the food is wastage.
                $line->wasted = true;
            } elseif ($cookingBegan) {
                $line->cost_paise = $this->cook($line, $line, StockMovement::WASTAGE, 'Cancelled after cooking began: '.$data['reason'], $eventId, $line->ref, $at);
                $line->consumed_at = $at;
                $line->business_date = Outlets::businessDate($outletId, $at);
                $line->wasted = true;
            }

            $line->save();

            return $line;
        });
    }

    /* ------------------------------------------------------------ sales */

    /**
     * A bill - from CRM (crm.sale.recorded), a CSV import or the sales API.
     *
     * Keyed by the other system's reference, so the same bill arriving
     * twice is recorded once; the second time, the first is handed back.
     *
     * @param  array{ref: string, invoice_no: string, business_date: string, channel: string, totals: array<string, int>, lines: list<array<string, mixed>>}  $sale
     * @return array{0: Sale, 1: bool} the sale, and whether this call made it
     */
    public function saleRecorded(string $tenantId, string $outletId, string $source, array $sale, ?string $eventId = null, ?CarbonInterface $at = null, ?int $importId = null): array
    {
        return DB::transaction(function () use ($tenantId, $outletId, $source, $sale, $eventId, $at, $importId) {
            $existing = Sale::allOutlets()->where('outlet_id', $outletId)->where('source', $source)->where('ref', $sale['ref'])->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $record = Sale::allOutlets()->create([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source' => $source,
                'ref' => $sale['ref'],
                'invoice_no' => $sale['invoice_no'],
                'business_date' => $sale['business_date'],
                'channel' => $sale['channel'],
                'net_paise' => (int) $sale['totals']['net_paise'],
                'tax_paise' => (int) $sale['totals']['tax_paise'],
                'round_off_paise' => (int) ($sale['totals']['round_off_paise'] ?? 0),
                'grand_paise' => (int) $sale['totals']['grand_paise'],
                'sales_import_id' => $importId,
            ]);

            $at ??= now();

            foreach (array_values($sale['lines']) as $index => $data) {
                [$item, $size] = $this->salesItem($tenantId, $outletId, $source, $data);
                $kitchenRefs = array_values($data['kitchen_refs'] ?? []);

                $line = $record->lines()->create([
                    'ref' => $data['ref'] ?? null,
                    'sales_item_id' => $item?->id ?? 0,
                    'sales_item_size_id' => $size?->id ?? 0,
                    'item_ref' => $data['item_ref'] ?? null,
                    'variant_ref' => $data['variant_ref'] ?? null,
                    'option_refs' => array_values($data['option_refs'] ?? []),
                    'name' => $data['name'],
                    'qty' => Decimal::parse((string) $data['qty'], 4),
                    'net_paise' => (int) $data['net_paise'],
                    'tax_paise' => (int) $data['tax_paise'],
                    'kitchen_refs' => $kitchenRefs,
                ]);

                if ($kitchenRefs !== []) {
                    // The kitchen counted this food, or will. The bill only
                    // says which lines it settled.
                    foreach ($kitchenRefs as $ref) {
                        KitchenLine::allOutlets()->updateOrCreate(
                            ['outlet_id' => $outletId, 'ref' => $ref],
                            ['tenant_id' => $tenantId, 'sale_line_id' => $line->id],
                        );
                    }

                    continue;
                }

                $line->cost_paise = $this->cook(
                    $line, $line, StockMovement::CONSUMPTION, null, $eventId,
                    $data['ref'] ?? 'line-'.($index + 1), $at, $outletId, $source, $sale['business_date'],
                );
                $line->save();
            }

            return [$record, true];
        });
    }

    /**
     * A bill was cancelled. Gives back what the bill itself took out of
     * stock; what the kitchen cooked for it stays used.
     */
    public function saleCancelled(string $outletId, string $source, string $ref, string $reason, ?string $eventId = null, ?CarbonInterface $at = null): ?Sale
    {
        return DB::transaction(function () use ($outletId, $source, $ref, $reason, $eventId, $at) {
            $sale = Sale::allOutlets()->where('outlet_id', $outletId)->where('source', $source)->where('ref', $ref)
                ->lockForUpdate()->first();

            if ($sale === null || $sale->isCancelled()) {
                return $sale;
            }

            $lineIds = $sale->lines()->pluck('id');

            $booked = StockMovement::allOutlets()
                ->where('reference_type', (new SaleLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->where('type', StockMovement::CONSUMPTION)
                ->orderBy('id')
                ->get();

            if ($booked->isNotEmpty()) {
                $at ??= now();

                $this->stock->reverse($booked, new Posting(
                    StockMovement::REVERSAL, $sale, 'Bill '.$sale->invoice_no.' cancelled: '.$reason,
                    $eventId, 'cancel', Outlets::businessDate($outletId, $at), $at,
                ));
            }

            $sale->lines()->update(['cost_paise' => 0]);
            $sale->update(['status' => Sale::CANCELLED, 'cancel_reason' => $reason]);

            return $sale;
        });
    }

    /**
     * crm.sale.returned - stock comes back only for the lines CRM marked
     * "back to stock", which it offers for packaged goods only.
     *
     * @param  array<string, mixed>  $data
     */
    public function saleReturned(string $outletId, array $data, string $eventId, CarbonInterface $at): void
    {
        DB::transaction(function () use ($outletId, $data, $eventId, $at) {
            $sale = Sale::allOutlets()->where('outlet_id', $outletId)->where('source', SalesItem::CRM)->where('ref', $data['sale_id'])->first();

            if ($sale === null) {
                return;
            }

            $kitchen = Outlets::stores($outletId)['kitchen'];

            foreach ($data['lines'] as $returned) {
                $line = $sale->lines()->where('ref', $returned['line_id'])->first();

                if ($line === null) {
                    continue;
                }

                $qty = Decimal::parse((string) $returned['qty'], 4);
                $line->increment('returned_qty', $qty);

                if (! $returned['back_to_stock']) {
                    continue;
                }

                $why = new Posting(
                    StockMovement::CUSTOMER_RETURN, $line, 'Returned on bill '.$sale->invoice_no,
                    $eventId, $returned['line_id'], $data['business_date'], $at,
                );

                foreach ($this->needs($outletId, $line->sales_item_id, $line->sales_item_size_id, $line->option_refs ?? [], $qty, SalesItem::CRM) as $itemId => $amount) {
                    $item = StockItem::allTenants()->findOrFail($itemId);
                    $this->stock->receive($kitchen, $item, $amount, $this->stock->costRate($outletId, $item), $why);
                }
            }
        });
    }

    /* ---------------------------------------------------------- private */

    /**
     * Take the ingredients of one line out of stock, and say what they cost.
     *
     * @param  KitchenLine|SaleLine  $line  what was cooked or sold
     */
    private function cook(
        KitchenLine|SaleLine $line,
        KitchenLine|SaleLine $reference,
        string $type,
        ?string $reason,
        ?string $eventId,
        string $eventLine,
        CarbonInterface $at,
        ?string $outletId = null,
        string $source = SalesItem::CRM,
        ?string $businessDate = null,
    ): int {
        $outletId ??= $line->outlet_id;

        $why = new Posting($type, $reference, $reason, $eventId, $eventLine, $businessDate ?? Outlets::businessDate($outletId, $at), $at);
        $cost = 0;

        foreach ($this->needs($outletId, $line->sales_item_id, $line->sales_item_size_id, $line->option_refs ?? [], $line->qty, $source) as $itemId => $qty) {
            $item = StockItem::allTenants()->findOrFail($itemId);

            $cost += abs((int) $this->stock->useAt($outletId, $item, $qty, $why)->sum('value_paise'));
        }

        return $cost;
    }

    /**
     * The ingredients of a line, in a fixed order - so replaying the same
     * events books the same movements in the same sequence.
     *
     * @return array<int, int>
     */
    private function needs(string $outletId, int $salesItemId, int $sizeId, array $optionRefs, int $portions, string $source): array
    {
        $needs = $this->recipes->ingredients($outletId, $salesItemId, $sizeId, $optionRefs, $portions, $source);
        ksort($needs);

        return $needs;
    }

    /** @return \Illuminate\Support\Collection<int, StockMovement> */
    private function bookedBy(KitchenLine $line)
    {
        return StockMovement::allOutlets()
            ->where('reference_type', $line->getMorphClass())
            ->where('reference_id', $line->id)
            ->where('type', StockMovement::CONSUMPTION)
            ->orderBy('id')
            ->get();
    }

    /**
     * The row for a kitchen line, made the first time it is heard of and
     * filled in when a bill had named it before the kitchen did.
     *
     * @param  array<string, mixed>  $data
     */
    private function kitchenLine(string $tenantId, string $outletId, array $data): KitchenLine
    {
        $line = KitchenLine::allOutlets()->firstOrNew(['outlet_id' => $outletId, 'ref' => $data['line_id']]);

        if ($line->item_ref === null) {
            [$item, $size] = $this->salesItem($tenantId, $outletId, SalesItem::CRM, [
                'item_ref' => $data['item_id'], 'variant_ref' => $data['variant_id'] ?? null, 'name' => '',
            ]);

            $line->fill([
                'tenant_id' => $tenantId,
                'order_ref' => $data['order_id'],
                'item_ref' => $data['item_id'],
                'variant_ref' => $data['variant_id'] ?? null,
                'option_refs' => array_values($data['option_ids'] ?? []),
                'qty' => Decimal::parse((string) $data['qty'], 4),
                'sales_item_id' => $item?->id ?? 0,
                'sales_item_size_id' => $size?->id ?? 0,
            ]);
        }

        return $line;
    }

    /**
     * The sales item and size a line is about. CRM's are looked up - a dish
     * ERP has not been told about is simply unknown - while a name from
     * other billing software is made into a sales item the first time it is
     * seen.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?SalesItem, 1: ?SalesItemSize}
     */
    private function salesItem(string $tenantId, string $outletId, string $source, array $data): array
    {
        // A CSV row already matched to one of ERP's sales items by a person.
        if (! empty($data['sales_item_id'])) {
            $item = SalesItem::allOutlets()->where('outlet_id', $outletId)->find($data['sales_item_id']);

            return [$item, $item === null || empty($data['sales_item_size_id'])
                ? null
                : SalesItemSize::query()->where('sales_item_id', $item->id)->find($data['sales_item_size_id'])];
        }

        $itemRef = $data['item_ref'] ?? null;

        if (blank($itemRef)) {
            return [null, null];
        }

        if ($source !== SalesItem::CRM) {
            return $this->menu->outside($tenantId, $outletId, $source, (string) $itemRef, (string) $data['name'], $data['variant_ref'] ?? null);
        }

        $item = SalesItem::allOutlets()->where('outlet_id', $outletId)->where('source', $source)->where('ref', $itemRef)->first();

        $size = $item !== null && filled($data['variant_ref'] ?? null)
            ? SalesItemSize::query()->where('sales_item_id', $item->id)->where('ref', $data['variant_ref'])->first()
            : null;

        return [$item, $size];
    }

    /** UTC moment of an event envelope's occurred_at. */
    public static function moment(string $occurredAt): CarbonImmutable
    {
        return CarbonImmutable::parse($occurredAt)->utc();
    }
}
