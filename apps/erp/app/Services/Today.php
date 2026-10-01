<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\PaidOut;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\SupplierBill;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Facades\Route;

/**
 * What the home screen says: the things that need somebody's attention at
 * an outlet, and three figures to steer by.
 *
 * Each to-do is a count and the screen that clears it. A to-do with nothing
 * in it is left off - the best home screen is an empty one.
 */
class Today
{
    /**
     * @return list<array{label: string, count: int, hint: string, url: string|null, tone: string}>
     */
    public function todo(string $outletId): array
    {
        $onHand = StockLevel::forOutlet($outletId)
            ->groupBy('stock_item_id')
            ->selectRaw('stock_item_id, SUM(qty) as qty')
            ->pluck('qty', 'stock_item_id');

        $items = StockItem::forTenant(Outlets::tenantOf($outletId))->active()->get(['id', 'reorder_qty']);

        $negative = $onHand->filter(fn ($qty) => (int) $qty < 0)->count();

        $low = $items->filter(fn (StockItem $item) => $item->reorder_qty > 0
            && (int) ($onHand[$item->id] ?? 0) >= 0
            && (int) ($onHand[$item->id] ?? 0) < $item->reorder_qty)->count();

        $today = Outlets::businessDate($outletId);
        $soon = now()->parse($today)->addDays(7)->toDateString();

        $expiring = Batch::forOutlet($outletId)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $soon.' 23:59:59')
            ->whereIn('id', StockLevel::forOutlet($outletId)->where('qty', '>', 0)->where('batch_id', '>', 0)->select('batch_id'))
            ->count();

        $todo = [
            [
                'label' => 'Gone negative',
                'count' => $negative,
                'hint' => 'Used more than the books say was there. A receipt or a count fixes it.',
                'url' => self::url('stock.index', ['show' => 'negative']),
                'tone' => 'danger',
            ],
            [
                'label' => 'Running low',
                'count' => $low,
                'hint' => 'Below the level you asked to be told at.',
                'url' => self::url('stock.index', ['show' => 'low']),
                'tone' => 'warning',
            ],
            [
                'label' => 'Expiring within a week',
                'count' => $expiring,
                'hint' => 'Batches to use first, or write off.',
                'url' => self::url('reports.show', ['report' => 'expiry']),
                'tone' => 'warning',
            ],
        ];

        foreach ($this->more($outletId) as $extra) {
            $todo[] = $extra;
        }

        return array_values(array_filter($todo, fn (array $row) => $row['count'] > 0));
    }

    /**
     * @return array{stock_value: int, received_today: int, wasted_week: int}
     */
    public function figures(string $outletId): array
    {
        $today = Outlets::businessDate($outletId);
        $weekAgo = now()->parse($today)->subDays(6)->toDateString();

        return [
            'stock_value' => (int) StockLevel::forOutlet($outletId)->where('qty', '>', 0)->get(['qty', 'cost_rate'])
                ->sum(fn (StockLevel $level) => Units::value($level->qty, $level->cost_rate)),
            'received_today' => (int) StockMovement::forOutlet($outletId)
                ->where('type', StockMovement::RECEIPT)
                ->whereDate('business_date', $today)
                ->sum('value_paise'),
            'wasted_week' => -(int) StockMovement::forOutlet($outletId)
                ->where('type', StockMovement::WASTAGE)
                ->whereDate('business_date', '>=', $weekAgo)
                ->sum('value_paise'),
        ];
    }

    /**
     * To-dos from the parts of ERP beyond the store room: a transfer on its
     * way here, dishes with no recipe, bills falling due, paid-outs nobody
     * has filed.
     *
     * @return list<array{label: string, count: int, hint: string, url: string|null, tone: string}>
     */
    protected function more(string $outletId): array
    {
        $today = Outlets::businessDate($outletId);

        return [
            [
                'label' => 'Transfers to receive',
                'count' => StockTransfer::allTenants()->awaitedAt($outletId)->count(),
                'hint' => 'Stock another outlet has sent here. It joins yours when you receive it.',
                'url' => self::url('transfers.index'),
                'tone' => 'info',
            ],
            [
                'label' => 'Dishes with no recipe',
                'count' => app(Recipes::class)->needsRecipe($outletId)->count(),
                'hint' => 'Selling them uses no stock and they have no cost.',
                'url' => self::url('recipes.index'),
                'tone' => 'warning',
            ],
            [
                'label' => 'Supplier bills overdue',
                'count' => SupplierBill::forOutlet($outletId)->open()->whereDate('due_on', '<', $today)->count(),
                'hint' => 'Past the date they were to be paid by.',
                'url' => self::url('bills.index'),
                'tone' => 'danger',
            ],
            [
                'label' => 'Paid-outs to file',
                'count' => PaidOut::forOutlet($outletId)->pending()->count(),
                'hint' => 'Cash that left the till: say whether it was a supplier or an expense.',
                'url' => self::url('paid-outs.index'),
                'tone' => 'info',
            ],
        ];
    }

    protected static function url(string $route, array $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }
}
