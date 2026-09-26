<?php

namespace App\Http\Controllers\Owner;

use App\Models\Outlet;
use App\Models\Plan;
use App\Services\Outlets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A business's outlets: the restaurants and cafes it runs. Each is what a
 * plan is bought for and what every row in CRM and ERP belongs to.
 */
final class OutletController
{
    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $tenant->loadMissing(['outlets.subscriptions.plan', 'outlets.entitlements']);

        return view('owner.outlets.index', ['outlets' => $tenant->outlets]);
    }

    public function create(): View
    {
        return view('owner.outlets.form', [
            'outlet' => new Outlet,
            'plans' => Plan::active()->get(),
            'states' => Outlet::STATES,
        ]);
    }

    public function store(Request $request, Outlets $outlets): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $data = $request->validate($this->rules($request) + [
            'plan' => ['required', Rule::exists('plans', 'code')->where('is_active', true)],
            'period' => ['required', Rule::in(Plan::PERIODS)],
        ]);

        $plan = Plan::where('code', $data['plan'])->firstOrFail();

        $outlets->open($tenant, $this->outletData($data), $plan, $data['period'], $request->user());

        return redirect()->route('outlets.index')->with('status', 'Outlet added and its trial started.');
    }

    public function edit(Outlet $outlet, Request $request): View
    {
        $this->authorizeOutlet($outlet, $request);

        return view('owner.outlets.form', [
            'outlet' => $outlet,
            'plans' => Plan::active()->get(),
            'states' => Outlet::STATES,
        ]);
    }

    public function update(Outlet $outlet, Request $request, Outlets $outlets): RedirectResponse
    {
        $this->authorizeOutlet($outlet, $request);

        $data = $request->validate($this->rules($request, $outlet));

        $outlets->update($outlet, $this->outletData($data));

        return redirect()->route('outlets.index')->with('status', 'Outlet saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Outlet $outlet = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:12',
                Rule::unique('outlets', 'code')->where('tenant_id', $tenantId)->ignore($outlet?->id),
            ],
            'gstin' => ['nullable', 'string', 'size:15'],
            'fssai' => ['nullable', 'string', 'size:14'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'size:6'],
            'state_code' => ['required', Rule::in(array_keys(Outlet::STATES))],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function outletData(array $data): array
    {
        $data['code'] = strtoupper($data['code']);
        unset($data['plan'], $data['period']);

        return $data;
    }

    private function authorizeOutlet(Outlet $outlet, Request $request): void
    {
        abort_unless($outlet->tenant_id === $request->user()->tenant_id, 404);
    }
}
