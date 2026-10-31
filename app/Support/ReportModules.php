<?php

namespace App\Support;

/**
 * Which line of business each report is about.
 *
 * Reports carry no `permission:` route middleware (each checks its own right
 * in ReportController), so the module gate that reads that middleware never
 * saw them: a till-only outlet could open the purchase and kitchen reports,
 * and one with Reports switched off could open all of them. Asked by the
 * controller (the door) and by the sidebar (the signpost), so the two agree.
 *
 * A report needs Reports itself and any one of the modules listed for it.
 * A report not listed is about selling, which Retail, POS or Dine-in does.
 */
final class ReportModules
{
    /** @var array<string, array<int, string>> */
    private const MODULES = [
        'purchases' => ['purchase'],
        'payables' => ['purchase'],
        'stock' => ['inventory'],
        'low-stock' => ['inventory'],
        'expiry' => ['inventory'],
        'transfers' => ['inventory'],
        'kitchen' => ['kitchen'],
        'tables' => ['dining'],
        'day-closing' => ['pos'],
        'outstanding' => ['customers'],
        'customers' => ['customers'],
        // Counter returns and supplier returns in one report.
        'returns' => ['retail', 'pos', 'dining', 'purchase'],
    ];

    private const SELLING = ['retail', 'pos', 'dining'];

    /** Does the branch in context run what this report is about? */
    public static function runs(string $report): bool
    {
        if (! Modules::enabled('reports')) {
            return false;
        }

        foreach (self::MODULES[$report] ?? self::SELLING as $module) {
            if (Modules::enabled($module)) {
                return true;
            }
        }

        return false;
    }
}
