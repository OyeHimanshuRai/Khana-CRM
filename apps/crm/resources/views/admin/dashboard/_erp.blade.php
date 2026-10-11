{{--
    What ERP knows, on the home screen.

    Included only for one outlet linked to ERP - see DashboardController::erp(),
    which also says which of these cards this reader gets. Nothing here reads
    ERP: each figure is fetched once the page is up (assets/js/erp-panels.js),
    so ERP being slow or down never holds up the screen people open first.
--}}

@php
    /*
     | One row of cards, drawn when any card in it is: the food cost (for a
     | reader who may see the margin) or the sold-out list (for anybody, when
     | something is actually off the menu).
     */
    $soldOut = $erp['sold_out'];
    $draws = $erp['food_cost'] || $soldOut->isNotEmpty();
@endphp

@if ($draws)
    <div class="dash-row">
        @if ($erp['food_cost'])
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <div class="dash-card-title">Food cost today</div>
                        <div class="dash-card-sub">What the food in today's sales cost, from ERP's recipes</div>
                    </div>
                </div>

                {{-- Filled with ErpPanelController::foodCost: the card's body,
                     and a footnote when some of the day's dishes have no recipe. --}}
                <div data-erp-panel="{{ route('admin.dashboard.food-cost') }}"
                     style="display:flex;flex-direction:column;flex:1 1 auto">
                    <div class="dash-card-body">
                        <p class="text-sm text-muted" style="margin:0">Asking ERP…</p>
                    </div>
                </div>
            </div>
        @endif

        {{--
            Sold out by stock, at this outlet. Read from CRM's own table (see
            DashboardController::erp()), so it is drawn with the page and never
            waits on ERP. Shown to anybody - it is what the kitchen cannot
            serve, not a profit figure - and only when something is off.
        --}}
        @if ($soldOut->isNotEmpty())
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <div class="dash-card-title">Sold out by stock</div>
                        <div class="dash-card-sub">{{ $soldOut->count() }} {{ \Illuminate\Support\Str::plural('dish', $soldOut->count()) }} ERP has taken off the menu here</div>
                    </div>
                </div>

                <div class="dash-card-body">
                    <ul class="plain-list">
                        @foreach ($soldOut as $dish)
                            <li>
                                <strong>{{ $dish['name'] }}</strong>
                                @if ($dish['sizes'] !== [])
                                    <span class="text-muted">— {{ implode(', ', $dish['sizes']) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
@endif
