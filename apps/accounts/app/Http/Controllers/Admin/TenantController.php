<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\ReferenceEvents;
use App\Services\Subscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every business on the platform, for the people who run the platform. A
 * business can be suspended - its rows are kept, its staff cannot sign in -
 * and brought back.
 */
final class TenantController
{
    // Filter values the list and the dashboard links use; "expired" covers suspended and ended.
    public const STATES = ['trial' => 'On trial', 'active' => 'Paid', 'grace' => 'In grace', 'expired' => 'Expired'];

    public function index(Request $request): View|StreamedResponse
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'status' => (string) $request->query('status'),
            'state' => (string) $request->query('state'),
            'plan' => (string) $request->query('plan'),
        ];

        $rows = $this->rows($filters);

        if ($request->query('export') === 'csv') {
            return $this->csv($rows);
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $tenants = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'filters' => $filters,
            'states' => self::STATES,
            'plans' => Plan::query()->orderBy('sort_order')->get(['code', 'name']),
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $tenant->loadMissing(['outlets.subscriptions.plan', 'outlets.subscriptions.payments', 'users']);

        return view('admin.tenants.show', ['tenant' => $tenant]);
    }

    public function extend(Tenant $tenant, Subscription $subscription, Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        abort_unless($subscription->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($subscription->ends_at === null) {
            return back()->with('error', 'This plan has no end date to extend.');
        }

        $subscriptions->extend($subscription, (int) $data['days'], $data['note'] ?? null);

        return back()->with('status', "Extended by {$data['days']} days.");
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

    // One row per business with its plan, state and activity; state and plan are worked out from dates, so filtered here.
    private function rows(array $filters): Collection
    {
        $now = now();
        $q = $filters['q'];

        return Tenant::query()
            ->withCount(['outlets', 'users'])
            ->withMax('users', 'last_login_at')
            ->with([
                'users' => fn ($query) => $query->where('is_owner', true),
                'subscriptions' => fn ($query) => $query->with('plan')
                    ->where(fn ($w) => $w->whereNull('replaced_at')->orWhere('replaced_at', '>', $now)),
            ])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$q}%")->orWhere('mobile', 'like', "%{$q}%"))))
            ->when(in_array($filters['status'], [Tenant::ACTIVE, Tenant::SUSPENDED, Tenant::CLOSED], true), fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->get()
            ->map(fn (Tenant $tenant) => $this->summary($tenant, $now))
            ->filter(fn (array $row) => match ($filters['state']) {
                'trial', 'active', 'grace' => $row['state'] === $filters['state'],
                'expired' => in_array($row['state'], [Subscription::SUSPENDED, Subscription::ENDED], true),
                default => true,
            })
            ->filter(fn (array $row) => $filters['plan'] === '' || in_array($filters['plan'], $row['plan_codes'], true))
            ->values();
    }

    // The business's best state across its outlets: one paid outlet makes it a paying business.
    private function summary(Tenant $tenant, $now): array
    {
        $rank = [Subscription::ACTIVE => 0, Subscription::TRIAL => 1, Subscription::GRACE => 2, Subscription::SUSPENDED => 3, Subscription::ENDED => 4];
        $best = null;

        foreach ($tenant->subscriptions as $subscription) {
            [$state, $until] = $subscription->status($now);

            if ($best === null || $rank[$state] < $rank[$best['state']]) {
                $best = ['state' => $state, 'until' => $until, 'label' => $subscription->stateLabel(), 'tone' => $subscription->stateTone()];
            }
        }

        $owner = $tenant->users->first();

        return [
            'tenant' => $tenant,
            'owner' => $owner,
            'plans' => $tenant->subscriptions->pluck('plan.name')->filter()->unique()->implode(', '),
            'plan_codes' => $tenant->subscriptions->pluck('plan.code')->filter()->unique()->all(),
            'state' => $best['state'] ?? null,
            'state_label' => $best['label'] ?? 'No plan',
            'state_tone' => $best['tone'] ?? 'default',
            'until' => $best['until'] ?? null,
            'last_login_at' => $tenant->users_max_last_login_at,
        ];
    }

    private function csv(Collection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Business', 'Owner', 'Email', 'Mobile', 'Status', 'Plan', 'State', 'Until', 'Outlets', 'People', 'Last login', 'Signed up']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['tenant']->name,
                    $row['owner']?->name,
                    $row['owner']?->email ?? $row['tenant']->email,
                    $row['owner']?->mobile ?? $row['tenant']->phone,
                    $row['tenant']->status,
                    $row['plans'],
                    $row['state_label'],
                    $row['until']?->toDateString(),
                    $row['tenant']->outlets_count,
                    $row['tenant']->users_count,
                    $row['last_login_at'],
                    $row['tenant']->created_at?->toDateString(),
                ]);
            }

            fclose($out);
        }, 'businesses-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
