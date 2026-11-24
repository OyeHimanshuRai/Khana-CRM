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
        <span class="badge badge-{{ $s['badge']['tone'] }}" data-license-badge="{{ $s['badge']['key'] }}">
            <span class="badge-dot"></span> {{ $s['badge']['label'] }}
        </span>
    </div>

    <dl class="sec-facts license-facts">
        <div><dt>Current plan</dt><dd>{{ $s['plan'] ?? '—' }}</dd></div>
        <div><dt>License status</dt><dd>{{ $s['license_status'] }}</dd></div>
        <div><dt>License activated</dt><dd>{{ $s['license_activated_at']?->format('j M Y') ?? '—' }}</dd></div>
        <div><dt>Subscription started</dt><dd>{{ $s['subscription_started_at']?->format('j M Y') ?? '—' }}</dd></div>
        <div><dt>Subscription expires</dt><dd>{{ $s['expires_at']?->format('j M Y') ?? 'No end date' }}</dd></div>
        <div>
            <dt>Days remaining</dt>
            <dd>
                @if ($s['days_left'] === null)
                    —
                @elseif ($s['days_left'] < 0)
                    <span class="text-danger">Expired {{ abs($s['days_left']) }} day{{ abs($s['days_left']) === 1 ? '' : 's' }} ago</span>
                @else
                    {{ $s['days_left'] }} day{{ $s['days_left'] === 1 ? '' : 's' }}
                @endif
            </dd>
        </div>
        <div><dt>Subscription status</dt><dd>{{ $s['subscription_status'] ?? '—' }}</dd></div>
    </dl>
</div>
