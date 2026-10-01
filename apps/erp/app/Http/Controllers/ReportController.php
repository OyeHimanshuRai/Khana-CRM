<?php

namespace App\Http\Controllers;

use App\Services\Reports;
use App\Support\Here;
use App\Support\Outlets;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Reports hub. Each tab is one report; the ones about a period share a
 * from-to filter that defaults to this month so far.
 */
final class ReportController
{
    public const REPORTS = ['food-cost', 'variance', 'stock-value', 'expiry', 'purchases', 'payables'];

    private const TITLES = [
        'food-cost' => ['Food cost', 'What was sold, what the food in it cost'],
        'variance' => ['Variance', 'What the recipes say was used, against what the counts say is gone'],
        'stock-value' => ['Stock value', 'What is on the shelf and what it is worth'],
        'expiry' => ['Expiry', 'Batches that have gone off or will within 30 days'],
        'purchases' => ['Purchases', 'What was bought, by supplier and by item'],
        'payables' => ['Payables', 'What is owed to each supplier, and how late it is'],
    ];

    private const PERIODIC = ['food-cost', 'variance', 'purchases'];

    public function __invoke(Request $request, Reports $reports, string $report): View
    {
        $outlet = Here::outlet();
        $today = Here::businessDate();

        $from = $request->date('from')?->toDateString() ?? substr($today, 0, 8).'01';
        $to = $request->date('to')?->toDateString() ?? $today;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return view('reports.show', [
            'report' => $report,
            'title' => self::TITLES[$report][0],
            'subtitle' => self::TITLES[$report][1].' - '.Outlets::name($outlet),
            'periodic' => in_array($report, self::PERIODIC, true),
            'from' => $from,
            'to' => $to,
            'data' => match ($report) {
                'food-cost' => $reports->foodCost($outlet, $from, $to),
                'variance' => $reports->variance($outlet, $from, $to),
                'stock-value' => $reports->stockValue($outlet),
                'expiry' => $reports->expiry($outlet),
                'purchases' => $reports->purchases($outlet, $from, $to),
                'payables' => $reports->payables($outlet),
            },
        ]);
    }
}
