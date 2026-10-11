<?php

namespace App\Http\Controllers\Stock;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Support\Here;
use App\Support\Outlets;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Batch labels: what goes on the tub or the crate so the kitchen uses the
 * oldest first - the item, its batch, when it came in or was made, and the
 * day it must be used by.
 *
 * One page, made to be printed. It is asked for by the paperwork that made
 * the batches (a delivery, a production note) or by the batches themselves,
 * and laid out for the paper in the printer: a sheet of stickers or a roll.
 * The last paper chosen is remembered, since a kitchen has one printer.
 */
final class LabelController
{
    /** key => what it is called, labels across, labels per page, label width and height in mm, the page's own size */
    public const PAPER = [
        'a4-24' => ['name' => 'A4 sheet of 24 (70 x 37 mm)', 'across' => 3, 'per_page' => 24, 'width' => 70, 'height' => 37, 'page' => 'A4'],
        'a4-12' => ['name' => 'A4 sheet of 12 (105 x 48 mm)', 'across' => 2, 'per_page' => 12, 'width' => 105, 'height' => 48, 'page' => 'A4'],
        'roll-50' => ['name' => 'Label roll, 50 x 25 mm', 'across' => 1, 'per_page' => 1, 'width' => 50, 'height' => 25, 'page' => '50mm 25mm'],
        'roll-100' => ['name' => 'Label roll, 100 x 50 mm', 'across' => 1, 'per_page' => 1, 'width' => 100, 'height' => 50, 'page' => '100mm 50mm'],
    ];

    private const MOST_COPIES = 50;

    public function __invoke(Request $request): View
    {
        $outlet = Here::outlet();
        $batches = $this->batches($request, $outlet);

        abort_if($batches->isEmpty(), 404, 'There is no batch here to label.');

        $paper = $request->query('paper', $request->session()->get('label_paper'));
        $paper = is_string($paper) && isset(self::PAPER[$paper]) ? $paper : 'a4-24';
        $request->session()->put('label_paper', $paper);

        $copies = max(1, min(self::MOST_COPIES, (int) $request->query('copies', 1)));

        // The day each batch first came onto a shelf here.
        $since = StockMovement::forOutlet($outlet)
            ->whereIn('batch_id', $batches->pluck('id'))
            ->where('qty', '>', 0)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, MIN(business_date) as since')
            ->pluck('since', 'batch_id');

        $labels = $batches->flatMap(fn (Batch $batch) => array_fill(0, $copies, [
            'item' => $batch->item?->name ?? 'Unknown item',
            'batch_no' => $batch->batch_no,
            'made_on' => $batch->made_on,
            'since' => isset($since[$batch->id]) ? now()->parse($since[$batch->id]) : null,
            'expiry' => $batch->expiry_date,
        ]));

        return view('labels.show', [
            'pages' => $labels->chunk(self::PAPER[$paper]['per_page']),
            'count' => $labels->count(),
            'batches' => $batches,
            'paper' => $paper,
            'layout' => self::PAPER[$paper],
            'papers' => self::PAPER,
            'copies' => $copies,
            'outletName' => Outlets::name($outlet),
            'keep' => $request->only(['batch', 'receipt', 'document']),
        ]);
    }

    /**
     * The batches asked for - by a delivery, by a production note or one by
     * one - and only those of this outlet, soonest to go off first.
     *
     * @return Collection<int, Batch>
     */
    private function batches(Request $request, string $outlet): Collection
    {
        $ids = collect((array) $request->query('batch'))->map(fn ($id) => (int) $id);

        if ($request->filled('receipt')) {
            $ids = $ids->merge(GoodsReceipt::forOutlet($outlet)->findOrFail((int) $request->query('receipt'))->lines()->pluck('batch_id'));
        }

        if ($request->filled('document')) {
            $ids = $ids->merge(StockDocument::forOutlet($outlet)->findOrFail((int) $request->query('document'))->lines()->pluck('batch_id'));
        }

        return Batch::forOutlet($outlet)
            ->with('item')
            ->whereIn('id', $ids->filter()->unique())
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')
            ->get();
    }
}
