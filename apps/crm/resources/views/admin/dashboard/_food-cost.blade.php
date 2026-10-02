{{--
    The day's food cost, as ERP has it. A fragment: the card in _erp asks for
    it once the page is up, and ErpPanelController::foodCost builds it.

    $day is null when ERP could not be read. Otherwise its money is in
    rupees, and `percent` is null when there is nothing to divide - nothing
    sold yet, or nothing sold that has a recipe - which is not the same as
    a food cost of nought and is not drawn as one.
--}}

@if ($day === null)
    <div class="dash-card-body">
        <x-khana::unreachable style="margin-bottom:0" />
    </div>
@else
    @php
        $money = fn (float $value) => '₹'.number_format($value, 0);
        $percent = $day['percent'] === null ? null : rtrim(rtrim(number_format($day['percent'], 1), '0'), '.').'%';
        $on = $day['date'] ? 'on '.$day['date']->format('d M Y') : 'today';
    @endphp

    <div class="dash-card-body">
        <div class="mix-head" style="margin-bottom:14px">
            <strong>{{ $percent ?? '—' }}</strong>
            <span>
                @if ($percent !== null)
                    of sales {{ $on }}
                @elseif ($day['sales'] > 0)
                    nothing sold {{ $on }} has a recipe yet
                @else
                    nothing sold yet {{ $on }}
                @endif
            </span>
        </div>

        <dl class="metrics">
            <div>
                <dt>What the food cost</dt>
                <dd>{{ $money($day['cost']) }}</dd>
            </div>
            <div>
                <dt>Sales, before tax</dt>
                <dd>{{ $money($day['sales']) }}</dd>
            </div>
        </dl>
    </div>

    {{-- A dish sold with no recipe adds to the sales and nothing to the cost,
         so the percentage above can only be too low. Said, so it is not
         trusted further than it deserves. --}}
    @if ($day['uncosted'] > 0)
        <div class="dash-card-foot">
            <x-icon name="alert" :size="14" />
            <span>{{ $day['uncosted'] }} {{ $day['uncosted'] === 1 ? 'dish has' : 'dishes have' }} no recipe, so the real figure is higher.</span>
        </div>
    @endif
@endif
