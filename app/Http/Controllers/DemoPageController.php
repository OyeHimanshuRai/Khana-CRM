<?php

namespace App\Http\Controllers;

use App\Support\SiteBrand;
use Illuminate\View\View;

/**
 * Book a demo, on a page of its own at /demo.
 *
 * It was a section at the bottom of the landing page reached by a #demo
 * anchor. A form people are asked to fill in is worth a URL they can be sent,
 * and the landing page keeps a button pointing here. The form itself is
 * unchanged and still posts to DemoRequestController.
 */
class DemoPageController extends Controller
{
    public function __invoke(): View
    {
        return view('demo.index', ['site' => SiteBrand::get()]);
    }
}
