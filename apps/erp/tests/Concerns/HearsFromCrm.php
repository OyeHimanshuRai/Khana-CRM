<?php

namespace Tests\Concerns;

use App\Models\Recipe;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Services\Recipes;
use App\Support\Units;
use Illuminate\Support\Collection;
use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Inbox\Processor;
use Khana\Kit\Events\Inbox\Receiver;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;

/**
 * CRM, as far as ERP can tell: signed batches of events arriving at the
 * inbox.
 *
 * Every event a test sends is first checked against its contract
 * (contracts/events), then signed and put through the kit's own receiver
 * and processor - the same door, the same order and the same transaction
 * the real thing uses. A test that passes here has not been helped by a
 * shortcut.
 */
trait HearsFromCrm
{
    private int $crmSeq = 0;

    private int $crmClock = 0;

    /**
     * Build one event the way CRM's outbox would.
     *
     * @param  array{tenant: string, outlet: string}  $at
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function crmEvent(array $at, string $type, array $data, ?string $occurredAt = null): array
    {
        // A minute apart by default, so "later" events are later.
        $occurredAt ??= gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-01T13:00:00Z') + 60 * $this->crmClock++);

        $event = [
            'id' => Ulid::generate(),
            'type' => $type,
            'version' => 1,
            'source' => 'crm',
            'seq' => ++$this->crmSeq,
            'occurred_at' => $occurredAt,
            'tenant_id' => $at['tenant'],
            'outlet_id' => $at['outlet'],
            'data' => $data,
        ];

        $this->assertSame([], app(ContractValidator::class)->problems($event), "{$type} as sent by this test breaks its contract");

        return $event;
    }

    /**
     * Deliver events through the inbox and apply them.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed> what the processor reported
     */
    protected function deliver(array $events): array
    {
        config(['khana.events.senders.crm' => 'test-secret-crm-to-erp']);

        $body = json_encode(['events' => $events], JSON_THROW_ON_ERROR);

        app(Receiver::class)->receive('crm', Signature::sign($body, 'test-secret-crm-to-erp'), $body);

        $report = app(Processor::class)->run();

        $failed = InboxEvent::query()->where('status', InboxEvent::FAILED)->get();
        $this->assertCount(0, $failed, 'An event failed to apply: '.$failed->pluck('error')->implode(' | '));

        return $report;
    }

    /**
     * Send one event and apply it.
     *
     * @param  array{tenant: string, outlet: string}  $at
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the event sent
     */
    protected function fromCrm(array $at, string $type, array $data, ?string $occurredAt = null): array
    {
        $event = $this->crmEvent($at, $type, $data, $occurredAt);
        $this->deliver([$event]);

        return $event;
    }

    /* ------------------------------------------------------ menu helpers */

    /**
     * A menu item as crm.menu.item_upserted carries it.
     *
     * @param  list<array{0: string, 1: int}>  $sizes  [name, rupees]
     * @return array<string, mixed>
     */
    protected function menuItem(string $name, int $rupees, array $sizes = [], array $extra = []): array
    {
        return array_merge([
            'item_id' => Ulid::generate(),
            'name' => $name,
            'category' => ['category_id' => Ulid::generate(), 'name' => 'Starters'],
            'food_type' => 'veg',
            'active' => true,
            'price_paise' => $rupees * 100,
            'sku' => null,
            'barcode' => null,
            'sizes' => array_map(fn (array $size, int $i) => [
                'variant_id' => Ulid::generate(),
                'name' => $size[0],
                'sku' => null,
                'barcode' => null,
                'price_paise' => $size[1] * 100,
                'is_default' => $i === 0,
            ], $sizes, array_keys($sizes)),
            'addons' => [],
        ], $extra);
    }

    /** The variant id CRM gave a size of a menu item. */
    protected function variant(array $menuItem, string $sizeName): string
    {
        return collect($menuItem['sizes'])->firstWhere('name', $sizeName)['variant_id'];
    }

    /**
     * Write a recipe for a dish ERP has been told about.
     *
     * @param  array<string, array{0: StockItem, 1: string|int, 2: string}>  $ingredients  [item, amount, unit]
     */
    protected function recipe(array $at, array $menuItem, ?string $sizeName, array $ingredients, array $optional = []): Recipe
    {
        $item = SalesItem::allOutlets()->where('outlet_id', $at['outlet'])->where('ref', $menuItem['item_id'])->firstOrFail();
        $size = $sizeName === null ? null : $item->sizes()->where('name', $sizeName)->firstOrFail();

        return app(Recipes::class)->save(
            $at['outlet'],
            ['sales_item_id' => $item->id, 'sales_item_size_id' => $size?->id ?? 0],
            array_map(fn (array $row) => [
                'item' => $row[0],
                'qty' => Units::toBase($row[1], $row[2], $row[0]->base_unit),
                'entered_qty' => (string) $row[1],
                'unit' => $row[2],
                'optional' => in_array($row[0]->id, $optional, true),
            ], array_values($ingredients)),
        );
    }

    /* ----------------------------------------------------- order helpers */

    /** @return array<string, mixed> the data of crm.kitchen.line_advanced */
    protected function kitchenLine(array $menuItem, ?string $sizeName, string|int $qty, string $stage = 'ready', array $extra = []): array
    {
        return array_merge([
            'order_id' => Ulid::generate(),
            'line_id' => Ulid::generate(),
            'item_id' => $menuItem['item_id'],
            'variant_id' => $sizeName === null ? null : $this->variant($menuItem, $sizeName),
            'option_ids' => [],
            'qty' => number_format((float) $qty, 4, '.', ''),
            'stage' => $stage,
        ], $extra);
    }

    /**
     * The data of crm.sale.recorded for some lines.
     *
     * @param  list<array{0: array<string, mixed>, 1: ?string, 2: string|int, 3: int, 4?: list<string>}>  $lines  [menu item, size, qty, rupees, kitchen line ids]
     * @return array<string, mixed>
     */
    protected function saleOf(array $lines, string $date = '2026-10-01', string $channel = 'dine_in'): array
    {
        $net = 0;

        $rows = array_map(function (array $line) use (&$net) {
            $net += $line[3] * 100;

            return [
                'line_id' => Ulid::generate(),
                'item_id' => $line[0]['item_id'],
                'variant_id' => $line[1] === null ? null : $this->variant($line[0], $line[1]),
                'option_ids' => [],
                'name' => $line[0]['name'],
                'qty' => number_format((float) $line[2], 4, '.', ''),
                'net_paise' => $line[3] * 100,
                'tax_paise' => 0,
                'order_line_ids' => $line[4] ?? [],
            ];
        }, $lines);

        return [
            'sale_id' => Ulid::generate(),
            'invoice_no' => 'POS/'.substr(Ulid::generate(), -6),
            'business_date' => $date,
            'channel' => $channel,
            'lines' => $rows,
            'totals' => ['net_paise' => $net, 'tax_paise' => 0, 'round_off_paise' => 0, 'grand_paise' => $net],
        ];
    }

    /* ----------------------------------------------------------- outbox */

    protected function outboxMark(): int
    {
        return (int) OutboxEvent::query()->max('id');
    }

    /** @return Collection<int, OutboxEvent> */
    protected function sent(string $type, int $since = 0): Collection
    {
        $events = OutboxEvent::query()->where('id', '>', $since)->where('type', $type)->orderBy('id')->get();

        foreach ($events as $event) {
            $envelope = $event->toEnvelope('erp')->toArray();
            $envelope['seq'] = max(1, (int) $envelope['seq']);

            $this->assertSame([], app(ContractValidator::class)->problems($envelope), "{$type} breaks its contract");
        }

        return $events;
    }
}
