<?php

namespace App\Http\Controllers\Stock;

use App\Models\Store;
use App\Support\Here;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The places stock sits at this outlet.
 *
 * The main store and the kitchen are made for every outlet and cannot be
 * switched off - goods are received into one and recipes are cooked from
 * the other. A bar or a cold room is added here as an extra store.
 */
final class StoreController
{
    public function index(): View
    {
        Here::mainStores();

        return view('stores.index', [
            'stores' => Store::forOutlet(Here::outlet())->withCount('levels')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:12', 'alpha_num', Rule::unique('stores', 'code')->where('outlet_id', $outlet)],
        ]);

        Store::forOutlet($outlet)->create([
            'outlet_id' => $outlet,
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'kind' => Store::OTHER,
        ]);

        return redirect()->route('stores.index')->with('status', $data['name'].' added.');
    }

    public function update(Request $request, int $store): RedirectResponse
    {
        $store = Store::forOutlet(Here::outlet())->findOrFail($store);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store->update([
            'name' => $data['name'],
            // The two every outlet runs on stay on.
            'is_active' => $store->kind === Store::OTHER ? (bool) ($data['is_active'] ?? false) : true,
        ]);

        return redirect()->route('stores.index')->with('status', $store->name.' saved.');
    }
}
