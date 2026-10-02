{{--
    What ERP knows, on the home screen.

    Included only for one outlet linked to ERP - see DashboardController::erp(),
    which also says which of these cards this reader gets. Nothing here reads
    ERP: each figure is fetched once the page is up (assets/js/erp-panels.js),
    so ERP being slow or down never holds up the screen people open first.
--}}

@php
    /*
     | One row of cards, drawn when any card in it is. Today that is only the
     | food cost; the sold-out list joins it at the place marked below.
     */
    $draws = $erp['food_cost'];
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
            ================================================================
            SOLD OUT BY STOCK - left empty on purpose.
            ================================================================

            The list of dishes ERP has taken off sale at this outlet goes
            here, as a second .dash-card beside the food cost.

            It is read from CRM's own table of stock-outs, not from ERP, so it
            needs no fetch and has no "not reachable" state - draw it with the
            page. And it is a list of what the kitchen cannot serve, not a
            profit figure: give it its own key in DashboardController::erp()
            and add that key to $draws above, rather than putting it behind
            `food_cost`, or a cashier who may not see the margin would not see
            what is off the menu either.
        --}}
    </div>
@endif
