<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Services\ReferenceEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The things the platform sells: CRM, ERP, or both as the Suite. Priced per
 * outlet, in whole rupees on screen and paise underneath. Changing a plan
 * does not move anyone already on it - a subscription keeps the price it was
 * bought at.
 */
final class PlanController
{
    public function index(): View
    {
        return view('admin.plans.index', ['plans' => Plan::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function create(): View
    {
        return view('admin.plans.form', ['plan' => new Plan(['is_active' => true, 'trial_days' => 14])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Plan::create($this->validated($request));

        return redirect()->route('admin.plans.index')->with('status', 'Plan created.');
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.form', ['plan' => $plan]);
    }

    public function update(Plan $plan, Request $request): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));

        return redirect()->route('admin.plans.index')->with('status', 'Plan saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:255'],
            'blurb' => ['nullable', 'string', 'max:255'],
            'products' => ['required', 'array', 'min:1'],
            'products.*' => [Rule::in(ReferenceEvents::PRODUCTS)],
            'monthly_price' => ['required', 'integer', 'min:0'],
            'yearly_price' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'blurb' => $data['blurb'] ?? null,
            'products' => array_values($data['products']),
            'monthly_price_paise' => $data['monthly_price'] * 100,
            'yearly_price_paise' => isset($data['yearly_price']) ? $data['yearly_price'] * 100 : null,
            'trial_days' => $data['trial_days'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }
}
