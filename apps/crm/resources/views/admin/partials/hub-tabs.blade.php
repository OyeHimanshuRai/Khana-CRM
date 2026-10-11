@php
    /*
     | The current hub's screens, as a row of links across the top of the
     | page - see App\Support\Navigation. Drawn only when there is somewhere
     | else to go: a hub this person sees one screen of needs no strip.
     |
     | Links, not tabs.js tabs: each is its own page with its own address,
     | so there is no data-tabs here and the script leaves it alone.
     */
    $hub = App\Support\Navigation::currentHub();
@endphp

@if ($hub && count($hub['tabs']) > 1)
    <nav class="hub-tabs" aria-label="{{ $hub['label'] }}">
        <div class="tabs">
            @foreach ($hub['tabs'] as $tab)
                @php
                    $url = App\Support\Navigation::href($tab);
                    $on = App\Support\Navigation::isActive($tab);
                @endphp

                <a class="tab {{ $on ? 'is-active' : '' }}"
                   href="{{ $url ?? '#' }}"
                   @if ($on) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
