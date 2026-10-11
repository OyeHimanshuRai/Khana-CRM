<?php

namespace App\Http\Controllers;

use App\Services\Today;
use App\Support\Here;
use App\Support\Outlets;
use Illuminate\View\View;

/**
 * Home: what needs doing at this outlet today, and the four things a
 * storekeeper does every day one tap away.
 */
final class HomeController
{
    public function __invoke(Today $today): View
    {
        $outlet = Here::outlet();

        return view('home', [
            'outletName' => Outlets::name($outlet),
            'todo' => $today->todo($outlet),
            'figures' => $today->figures($outlet),
        ]);
    }
}
