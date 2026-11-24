<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LicenseService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use App\Support\LicenseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use RuntimeException;

/**
 * The customer's side of a license: register a key once, then see where it stands.
 *
 * Deliberately tiny. The only thing a customer can send is the key itself;
 * the plan, the expiry, the status and the customer all come from the
 * database and the signed-in session, never from the request.
 */
class LicenseActivationController extends Controller
{
    public function __construct(private readonly LicenseService $licenses) {}

    public function show(Request $request): View|RedirectResponse
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return redirect()->route('admin.dashboard');
        }

        $summary = LicenseAccess::summary($tenant);

        return view('admin.license.show', [
            'tenant' => $tenant,
            'summary' => $summary,
            'canActivate' => $request->user()->can('settings.subscriptions.pay')
                && in_array($summary['state'], [LicenseAccess::REQUIRED, LicenseAccess::REVOKED], true),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return ApiResponse::error('Your account is not linked to a company.', [], 403);
        }

        $data = $request->validate([
            'license_key' => ['required', 'string', 'min:8', 'max:60'],
        ], [
            'license_key.required' => 'Enter your license key.',
        ]);

        $limiter = 'license-activate:'.$tenant->id;

        if (RateLimiter::tooManyAttempts($limiter, (int) config('license.max_attempts', 8))) {
            return ApiResponse::error('Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        try {
            $this->licenses->activate($request->user(), $tenant, $data['license_key'], $request);
        } catch (RuntimeException $e) {
            RateLimiter::hit($limiter, 600);

            return ApiResponse::error($e->getMessage(), ['license_key' => [$e->getMessage()]]);
        }

        RateLimiter::clear($limiter);

        return ApiResponse::success(
            'License submitted. You will get access as soon as it is approved.',
            ['state' => LicenseAccess::PENDING],
            route('admin.license.show'),
        );
    }

    /** Polled by the waiting page, so approval takes the customer straight in. */
    public function status(): JsonResponse
    {
        $tenant = CurrentTenant::get();
        $state = $tenant === null ? LicenseAccess::REQUIRED : LicenseAccess::check($tenant)[0];

        return ApiResponse::success('', [
            'state' => $state,
            'redirect' => $state === LicenseAccess::OK ? route('admin.dashboard') : null,
        ]);
    }
}
