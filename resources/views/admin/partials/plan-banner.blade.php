{{--
    A customer's free trial counting down, or a plan about to end, at the top of every page.

    Not for the platform's own admin, not on the screens that already say it (billing, license), and not
    when the customer is restricted (they are on the payment screen already).
--}}
@php
    $bannerUser = auth()->user();
    $bannerTenant = ($bannerUser && ! $bannerUser->isSuperAdmin() && $bannerUser->tenant_id) ? \App\Support\CurrentTenant::get() : null;
    $banner = null;

    if ($bannerTenant && ! request()->routeIs('admin.subscription.*', 'admin.license.*') && \App\Support\AccountAccess::restricted($bannerUser) === null) {
        $bs = \App\Support\LicenseAccess::summary($bannerTenant);

        if ($bs['on_trial'] && ($bs['seconds_left'] ?? 0) > 0) {
            $banner = ['tone' => 'info', 'title' => 'Free trial', 'text' => 'ends in', 'ends' => $bs['ends_timestamp'], 'secs' => (int) $bs['seconds_left'], 'cta' => 'Pay now to keep your plan'];
        } elseif ($bs['warning'] && ($bs['seconds_left'] ?? 0) > 0) {
            $banner = ['tone' => 'warning', 'title' => 'Your plan', 'text' => 'ends in', 'ends' => $bs['ends_timestamp'], 'secs' => (int) $bs['seconds_left'], 'cta' => 'Renew now'];
        }
    }
@endphp

@if ($banner)
    @php
        $d = intdiv($banner['secs'], 86400);
        $h = intdiv($banner['secs'] % 86400, 3600);
        $m = intdiv($banner['secs'] % 3600, 60);
        $left = $d > 0 ? $d.' day'.($d === 1 ? '' : 's').' '.$h.' hour'.($h === 1 ? '' : 's') : $h.' hour'.($h === 1 ? '' : 's').' '.$m.' min';
    @endphp
    <div class="banner banner-{{ $banner['tone'] }}" role="status">
        <div class="banner-body">
            <strong>{{ $banner['title'] }} {{ $banner['text'] }}
                <span data-license-countdown="{{ $banner['ends'] }}">{{ $left }}</span>.</strong>
            After that nothing works until the plan you chose is paid for.
        </div>
        @if ($bannerUser->can('settings.subscriptions.view'))
            <a class="btn btn-sm btn-primary" href="{{ route('admin.subscription.mine') }}">{{ $banner['cta'] }}</a>
        @endif
    </div>

    @pushOnce('scripts', 'license-js')
        <script src="{{ asset('assets/js/license.js') }}?v={{ filemtime(public_path('assets/js/license.js')) }}" defer></script>
    @endPushOnce
@endif
