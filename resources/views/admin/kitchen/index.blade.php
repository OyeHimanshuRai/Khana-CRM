@extends('admin.layouts.app')

@section('title', 'Kitchen Display')

@section('content')
    <x-page-header
        title="Kitchen Display"
        subtitle="Every ticket the kitchen still has work to do on, oldest first."
        :crumbs="['Kitchen' => null, 'Display' => null]"
    >
        <x-slot:actions>
            {{--
                Fullscreen, because this screen is usually bolted to a wall and
                the sidebar is a hundred pixels of nothing to a cook. The API
                needs a user gesture, which is why it is a button and not
                something the page does on load.
            --}}
            <button type="button" class="btn btn-sm" data-kds-fullscreen>
                <x-icon name="grid" :size="15" /> Full screen
            </button>

            {{--
                Sound is off until somebody asks for it. Browsers block audio
                until a page has been interacted with anyway, and a screen that
                started beeping the moment it loaded would be muted at the wall
                switch within a week.
            --}}
            <button type="button" class="btn btn-sm" data-kds-sound aria-pressed="false">
                <x-icon name="bell" :size="15" /> <span data-kds-sound-label>Sound off</span>
            </button>

            @allows('kitchen.stations.view')
                <a class="btn btn-sm" href="{{ route('admin.kitchen-stations.index') }}">
                    <x-icon name="tool" :size="15" /> Stations
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid kds-counts" data-kds-counts>
        @include('admin.kitchen._counts')
    </div>

    {{-- data-kds-shop lets realtime.js subscribe to this branch. Absent in
         all-shops mode, and absent means "poll only", which is correct:
         a socket is per branch and a consolidated view spans several. --}}
    <div class="card kds" data-ajax-list="{{ route('admin.kitchen.index') }}" data-kds
         @if (App\Support\CurrentShop::id()) data-kds-shop="{{ App\Support\CurrentShop::id() }}" @endif
         data-kds-every="7">
        <form method="GET" class="list-toolbar kds-toolbar">
            {{--
                Stations are plain links, not filters. The choice is kept in the
                session, so a screen on the wall by the tandoor comes back on
                the tandoor after a reload - including the reload a kiosk
                browser does when the building's power blinks.

                Links, not an ARIA tab widget, for the same reason: each one is
                a different URL a kiosk can be pointed at, and role="tab" would
                promise a screen reader arrow-key behaviour that is not here.
                aria-current says which page this is, which is the truth.
            --}}
            <nav class="kds-tabs" aria-label="Kitchen station">
                <a class="kds-tab {{ $station ? '' : 'is-on' }}"
                   href="{{ route('admin.kitchen.index', ['station' => 'all']) }}"
                   @if (! $station) aria-current="page" @endif>
                    All stations
                </a>

                @foreach ($stations as $one)
                    <a class="kds-tab {{ $station?->id === $one->id ? 'is-on' : '' }}"
                       href="{{ route('admin.kitchen.index', ['station' => $one->id]) }}"
                       @if ($station?->id === $one->id) aria-current="page" @endif>
                        {{ $one->name }}
                    </a>
                @endforeach
            </nav>

            <div class="list-filters">
                <label for="kds-type" class="sr-only">Order type</label>
                <select id="kds-type" name="type" data-ajax-filter>
                    <option value="">Every channel</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>

                {{-- Without JavaScript nothing polls, so say so rather than
                     leave a cook watching a screen that will never change. --}}
                <noscript><span class="text-xs text-muted">Reload to see new tickets.</span></noscript>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.kitchen._board')
        </div>
    </div>
@endsection
