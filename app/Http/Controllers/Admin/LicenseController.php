<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\LicenseService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The Super Admin's license actions, used from the Companies screen.
 *
 * Reading is `settings.licenses.view`, everything that changes a license is
 * `settings.licenses.edit` - and both are only ever held by the platform.
 * Every write goes through LicenseService, which locks the row, runs in a
 * transaction and writes the audit line; this class only validates the form
 * and says what happened.
 */
class LicenseController extends Controller
{
    /** Actions reachable through act(), and the validation each one takes. */
    private const ACTIONS = [
        'approve', 'reject', 'deactivate', 'reactivate', 'block', 'unblock',
        'revoke', 'reset', 'renew', 'extend', 'expiry', 'plan', 'assign',
        'block-customer', 'unblock-customer', 'rotate',
    ];

    public function __construct(private readonly LicenseService $licenses) {}

    /**
     * A company's license, inside the Companies screen - there is no separate
     * licenses page. Rendered into a modal: the license (or the form to give
     * the company one), its actions, activation attempts and history.
     */
    public function forTenant(Tenant $tenant): View
    {
        $license = $tenant->latestLicense()->with(['tenant.subscription.plan', 'plan', 'approver', 'activatedBy'])->first();

        return view('admin.licenses._tenant', [
            'tenant' => $tenant,
            'license' => $license,
            'activations' => $license?->activations()->with(['tenant', 'user', 'decider'])->get() ?? collect(),
            'logs' => $license?->logs()->with(['user'])->limit(60)->get() ?? collect(),
            'plans' => Plan::query()->orderBy('sort_order')->get(),
            'canEdit' => $this->canEdit(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authoriseEdit();

        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->whereNull('deleted_at')],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650', 'required_without:expires_at'],
            'expires_at' => ['nullable', 'date', 'after:today', 'required_without:validity_days'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            [$license, $key] = $this->licenses->generate($data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        // The one and only time the plain key leaves the server.
        return ApiResponse::success('License key generated. Copy it now - it cannot be shown again.', [
            'key' => $key,
            'id' => $license->id,
            'hint' => $license->key_hint,
        ]);
    }

    /**
     * One endpoint per change, behind one whitelist.
     *
     * Nothing a customer can reach lands here: the route group is
     * `settings.licenses.edit`, and the controller checks it again.
     */
    public function act(Request $request, License $license, string $action): JsonResponse
    {
        $this->authoriseEdit();
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        if ($action === 'rotate') {
            try {
                [$rotated, $key] = $this->licenses->rotateKey($license);
            } catch (RuntimeException $e) {
                return ApiResponse::error($e->getMessage());
            }

            return ApiResponse::success('New key issued. Copy it now - it cannot be shown again.', [
                'key' => $key, 'hint' => $rotated->key_hint, 'status' => $rotated->status,
            ]);
        }

        $reason = fn () => $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $message = match ($action) {
                'approve' => $this->done($this->licenses->approve($license), 'License approved. The customer now has access.'),
                'reject' => $this->done($this->licenses->reject($license, $reason()), 'Activation rejected. The key can be entered again.'),
                'deactivate' => $this->done($this->licenses->deactivate($license, $reason()), 'License deactivated. The customer is locked out until it is switched back on.'),
                'reactivate' => $this->done($this->licenses->reactivate($license), 'License activated again.'),
                'block' => $this->done($this->licenses->block($license, $reason()), 'License blocked.'),
                'unblock' => $this->done($this->licenses->unblock($license), 'License unblocked.'),
                'revoke' => $this->done($this->licenses->revoke($license, $reason()), 'License revoked. The key can never be used again.'),
                'reset' => $this->done($this->licenses->reset($license, $reason()), 'Activation reset. The customer must register the key again.'),
                'renew' => $this->renew($request, $license),
                'extend' => $this->extend($request, $license),
                'expiry' => $this->expiry($request, $license),
                'plan' => $this->plan($request, $license),
                'assign' => $this->assign($request, $license),
                'block-customer' => $this->blockCustomer($request, $license),
                'unblock-customer' => $this->unblockCustomer($license),
            };
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success($message, ['status' => $license->fresh()->status]);
    }

    /* ----------------------------------------------------------- actions */

    private function done(License $license, string $message): string
    {
        return $message;
    }

    private function renew(Request $request, License $license): string
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);
        $l = $this->licenses->renew($license, $data['days'] ?? null);

        return 'Renewed. It now runs until '.$l->expires_at->format('j M Y').'.';
    }

    private function extend(Request $request, License $license): string
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:3650']]);
        $l = $this->licenses->extend($license, (int) $data['days']);

        return 'Extended. It now runs until '.$l->expires_at->format('j M Y').'.';
    }

    private function expiry(Request $request, License $license): string
    {
        $data = $request->validate(['expires_at' => ['required', 'date', 'after:today']]);
        $l = $this->licenses->setExpiry($license, $data['expires_at']);

        return 'Expiry set to '.$l->expires_at->format('j M Y').'.';
    }

    private function plan(Request $request, License $license): string
    {
        $data = $request->validate(['plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->whereNull('deleted_at')]]);
        $l = $this->licenses->changePlan($license, Plan::query()->findOrFail($data['plan_id']));

        return 'Plan changed to '.$l->plan->name.'.';
    }

    private function assign(Request $request, License $license): string
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id']]);
        $tenant = ! empty($data['tenant_id']) ? Tenant::query()->findOrFail($data['tenant_id']) : null;
        $this->licenses->assign($license, $tenant);

        return $tenant ? 'Assigned to '.$tenant->name.'.' : 'Assignment removed.';
    }

    private function blockCustomer(Request $request, License $license): string
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        if ($license->tenant === null) {
            throw new RuntimeException('This license has no customer to block.');
        }

        $this->licenses->blockCustomer($license->tenant, $data['reason']);

        return $license->tenant->name.' is blocked and cannot sign in.';
    }

    private function unblockCustomer(License $license): string
    {
        if ($license->tenant === null) {
            throw new RuntimeException('This license has no customer to unblock.');
        }

        $this->licenses->unblockCustomer($license->tenant);

        return $license->tenant->name.' is unblocked.';
    }

    /* ------------------------------------------------------------ guards */

    private function canEdit(): bool
    {
        return (bool) auth()->user()?->can('settings.licenses.edit');
    }

    private function authoriseEdit(): void
    {
        abort_unless($this->canEdit(), 403, 'You may not change licenses.');
    }
}
