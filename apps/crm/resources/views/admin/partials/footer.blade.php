@php
    /*
     | The Support link was a `data-soon` placeholder until the desk existed
     | (§2). It now points at the real thing - but only for somebody who may
     | open it, because a footer link that 403s is worse than no link.
     */
    $support = auth()->user()?->can('support.tickets.view')
        ? route('admin.support.index')
        : null;
@endphp

<footer class="app-footer">
    <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
    <span>
        v1.0.0 &middot;
        @if ($support)
            <a href="{{ $support }}">Support</a>
        @else
            <a href="#" data-soon data-label="Support">Support</a>
        @endif
    </span>
</footer>
