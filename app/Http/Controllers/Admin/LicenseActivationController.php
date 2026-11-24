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
            'brand' => \App\Support\SiteBrand::get(),
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
            'License activated. Your plan has started.',
            ['state' => LicenseAccess::OK],
            route('admin.dashboard'),
        );
    }

    /** "I have not got my key": tell the platform, once an hour per company. */
    public function requestKey(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return ApiResponse::error('Your account is not linked to a company.', [], 403);
        }

        $limiter = 'license-key-request:'.$tenant->id;

        if (RateLimiter::tooManyAttempts($limiter, 1)) {
            return ApiResponse::success('We have already told the team. They will contact you soon - or call us on the number below.');
        }

        RateLimiter::hit($limiter, 3600);

        $this->licenses->requestKey($tenant, $request->user(), $request);

        return ApiResponse::success('Request sent. Our team will contact you with your license key.');
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
