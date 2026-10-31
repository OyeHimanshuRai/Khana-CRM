<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\TallyExportService;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The accountant's export: one branch, one period, one file for Tally.
 *
 * One branch at a time, always. Each outlet has its own GSTIN and its own
 * company in Tally, and an "All shops" file would post two companies' sales
 * into whichever one happened to be open.
 */
class TallyExportController extends Controller
{
    public function __construct(private readonly TallyExportService $tally) {}

    public function index(Request $request): View
    {
        return view('admin.reports.tally', [
            'range' => ReportFilters::fromRequest($request, 'last_month'),
            'presets' => ReportFilters::PRESETS,
            'types' => TallyExportService::TYPES,
            'shop' => CurrentShop::get(),
            'canDownload' => $request->user()->can('reports.tally_export.download'),
        ]);
    }

    public function download(Request $request): Response|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()->route('admin.reports.tally')
                ->with('error', 'Pick one branch first. Each branch is its own company in Tally.');
        }

        $data = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', 'in:'.implode(',', array_keys(TallyExportService::TYPES))],
            'masters' => ['nullable', 'boolean'],
        ], [
            'types.required' => 'Choose at least one kind of voucher to export.',
        ]);

        $range = ReportFilters::fromRequest($request, 'last_month');

        $xml = $this->tally->export($shop, $range, $data['types'], $request->boolean('masters'));

        $counts = $this->tally->counts();

        ActivityLog::record(
            'report.tally_exported',
            sprintf(
                'Exported %d voucher(s) to Tally for %s (%s)',
                array_sum($counts),
                $shop->name,
                $range->label(),
            ),
        );

        $filename = sprintf('tally-%s-%s.xml', Str::slug($shop->code ?: $shop->name), $range->slug());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // What went into the file, for whoever opens it next.
            'X-Tally-Vouchers' => (string) array_sum($counts),
        ]);
    }
}
