<?php

namespace App\Http\Controllers;

use App\Support\PricingPlans;
use App\Support\SiteBrand;
use Illuminate\View\View;

/**
 * The plans and what they cost, on a page of their own at /pricing.
 *
 * This used to be a section of the landing page reached by a #pricing anchor.
 * A price list is the page people send each other and come back to, so it has
 * a URL that is a page, and the landing keeps a one-line pointer to it.
 *
 * With nothing for sale there is nothing to show, so it is a 404 rather than
 * a page that says "no plans" - the same answer /register gives.
 */
class PricingController extends Controller
{
    public function __invoke(): View
    {
        $plans = PricingPlans::get();

        abort_if($plans->isEmpty(), 404);

        return view('pricing.index', [
            'site' => SiteBrand::get(),
            'plans' => $plans,
        ]);
    }
}
