@php
    /*
     | The current hub's screens, as a row of links across the top of the
     | page - see App\Support\Navigation and config/menu.php. Drawn only when
     | there is somewhere else to go.
     |
     | Links, not tabs.js tabs: each is its own page with its own address,
     | so there is no data-tabs here and the script leaves it alone.
     */
    $hub = App\Support\Navigation::currentHub();
@endphp

@if ($hub && count($hub['tabs']) > 1)
    <nav class="hub-tabs" aria-label="{{ $hub['label'] }}" style="margin-bottom:18px">
        <div class="tabs" style="border-radius:10px 10px 0 0">
            @foreach ($hub['tabs'] as $tab)
                @php $on = App\Support\Navigation::isActive($tab); @endphp

                <a class="tab {{ $on ? 'is-active' : '' }}"
                   href="{{ App\Support\Navigation::href($tab) ?? '#' }}"
                   style="text-decoration:none;padding:12px 18px"
                   @if ($on) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
