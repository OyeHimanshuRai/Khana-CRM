<?php

namespace App\Http\Controllers\Admin;

use App\Models\Tenant;
use App\Services\ReferenceEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every business on the platform, for the people who run the platform. A
 * business can be suspended - its rows are kept, its staff cannot sign in -
 * and brought back.
 */
final class TenantController
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $tenants = Tenant::query()
            ->withCount(['outlets', 'users'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.tenants.index', ['tenants' => $tenants, 'search' => $search]);
    }

    public function show(Tenant $tenant): View
    {
        $tenant->loadMissing(['outlets.subscriptions.plan', 'users']);

        return view('admin.tenants.show', ['tenant' => $tenant]);
    }

    public function setStatus(Tenant $tenant, Request $request, ReferenceEvents $events): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,closed'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($tenant, $data, $events) {
            $tenant->update(['status' => $data['status'], 'status_reason' => $data['reason'] ?? null]);
            $events->tenant($tenant);

            // Suspending or closing a business signs its people out of every
            // product at once.
            if ($data['status'] !== Tenant::ACTIVE) {
                foreach ($tenant->users()->get() as $user) {
                    $events->sessionsRevoked($user, 'security');
                }
            }
        });

        return back()->with('status', "Business marked {$data['status']}.");
    }
}
