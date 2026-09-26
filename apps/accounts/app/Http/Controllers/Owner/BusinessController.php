<?php

namespace App\Http\Controllers\Owner;

use App\Services\ReferenceEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The business's own details: its name, legal name, GSTIN, PAN. What a bill
 * is issued under. Editing it tells CRM and ERP at once.
 */
final class BusinessController
{
    public function edit(Request $request): View
    {
        return view('owner.business', ['tenant' => $request->user()->tenant]);
    }

    public function update(Request $request, ReferenceEvents $events): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'pan' => ['nullable', 'string', 'size:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $tenant->update($data);
        $events->tenant($tenant->refresh());

        return back()->with('status', 'Business details saved.');
    }
}
