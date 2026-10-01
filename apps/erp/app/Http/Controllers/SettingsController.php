<?php

namespace App\Http\Controllers;

use App\Models\OutletSetting;
use App\Services\Availability;
use App\Support\Here;
use App\Support\Outlets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * How stock behaves at this outlet: four switches, each a sentence.
 *
 * Everything else about an outlet - its name, its people, its plan - is
 * Khana Accounts' to say.
 */
final class SettingsController
{
    public function edit(): View
    {
        $outlet = Here::outlet();

        return view('settings.edit', [
            'outletName' => Outlets::name($outlet),
            'settings' => Outlets::settings($outlet),
            'stages' => OutletSetting::STAGES,
        ]);
    }

    public function update(Request $request, Availability $availability): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'consume_stage' => ['required', Rule::in(array_keys(OutletSetting::STAGES))],
            'exclude_expired' => ['nullable', 'boolean'],
            'input_tax_is_cost' => ['nullable', 'boolean'],
            'kitchen_only' => ['nullable', 'boolean'],
        ]);

        OutletSetting::query()->updateOrCreate(['outlet_id' => $outlet], [
            'tenant_id' => Here::tenant(),
            'consume_stage' => $data['consume_stage'],
            'exclude_expired' => (bool) ($data['exclude_expired'] ?? false),
            'input_tax_is_cost' => (bool) ($data['input_tax_is_cost'] ?? false),
            'kitchen_only' => (bool) ($data['kitchen_only'] ?? false),
        ]);

        // Which stock counts towards "can we still make this" may have
        // just changed.
        $availability->recheck($outlet);

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }
}
