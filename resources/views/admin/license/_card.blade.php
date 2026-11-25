{{--
    The customer's plan, license and expiry at a glance.

    Needs $summary (App\Support\LicenseAccess::summary). Used on the dashboard
    and on the license page; it has no script of its own.
--}}
@php $s = $summary; @endphp

@if ($s['warning'])
    <div class="alert alert-warning" style="margin-bottom:14px" role="alert">
        <strong>Renewal reminder.</strong> {{ $s['warning'] }}
    </div>
@endif

<div class="card license-card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h3 class="card-title" style="margin:0">Plan &amp; License</h3>
        <span style="display:inline-flex;gap:6px;align-items:center">
        @if ($s['plan_kind'])<span class="badge badge-info">{{ $s['plan_kind'] }}</span>@endif
        <span class="badge badge-{{ $s['badge']['tone'] }}" data-license-badge="{{ $s['badge']['key'] }}">
            <span class="badge-dot"></span> {{ $s['badge']['label'] }}
        </span>
        </span>
    </div>

    <dl class="sec-facts license-facts">
        <div><dt>Current plan</dt><dd>{{ $s['plan'] ?? '—' }}</dd></div>
        <div><dt>License status</dt><dd>{{ $s['license_status'] }}</dd></div>
        <div><dt>License activated</dt><dd>{{ $s['license_activated_at']?->format('j M Y') ?? '—' }}</dd></div>
        <div><dt>Subscription started</dt><dd>{{ $s['subscription_started_at']?->format('j M Y') ?? '—' }}</dd></div>
        <div><dt>Subscription expires</dt><dd>{{ $s['expires_at']?->format('j M Y') ?? 'No end date' }}</dd></div>
        <div>
            <dt>{{ $s['on_trial'] ? 'Free trial ends in' : 'Time remaining' }}</dt>
            <dd>
                @if ($s['seconds_left'] === null)
                    —
                @elseif ($s['seconds_left'] < 0)
                    <span class="text-danger">Expired</span>
                @else
                    {{-- Kept ticking by license.js; the server-rendered text is what shows without it. --}}
                    <span data-license-countdown="{{ $s['ends_timestamp'] }}">
                        @php
                            $secs = (int) $s['seconds_left'];
                            $d = intdiv($secs, 86400);
                            $h = intdiv($secs % 86400, 3600);
                            $m = intdiv($secs % 3600, 60);
                        @endphp
                        @if ($d > 0)
                            {{ $d }} day{{ $d === 1 ? '' : 's' }} {{ $h }} hour{{ $h === 1 ? '' : 's' }}
                        @else
                            {{ $h }} hour{{ $h === 1 ? '' : 's' }} {{ $m }} min
                        @endif
                    </span>
                @endif
            </dd>
        </div>
        <div><dt>Subscription status</dt><dd>{{ $s['subscription_status'] ?? '—' }}</dd></div>
    </dl>
</div>

@once
    @push('scripts')
        <script src="{{ asset('assets/js/license.js') }}?v={{ filemtime(public_path('assets/js/license.js')) }}" defer></script>
    @endpush
@endonce
