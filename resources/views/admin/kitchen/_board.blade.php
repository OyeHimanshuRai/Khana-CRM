{{--
    The board itself: the fragment the wall screen re-fetches every few
    seconds. Everything that changes during service is inside this file, and
    everything that does not - the header, the station tabs - is outside it,
    so a poll never rebuilds the controls somebody is reaching for.

    Four columns, one per rung of the kitchen ladder. `served` has no column:
    a plate that has gone out is history, and a board that kept showing it is
    a board nobody can read by nine o'clock.

    The column words are Order::STATUSES, not a kitchen-only set. A guest's
    phone, the bill screen and this all say "Preparing" about the same ticket,
    and a private vocabulary here would be one more thing to translate when
    somebody walks over to ask.

    The counts above the card ride along with every refresh as an out-of-band
    template - see data-ajax-oob in public/assets/js/ajax-list.js. They are
    computed from the same tickets this board draws, so sending them together
    is what keeps the two from disagreeing about the same service.
--}}

<template data-ajax-oob="[data-kds-counts]">@include('admin.kitchen._counts')</template>

@php
    use App\Models\Order;

    /*
     | This station's lines on a ticket - or every kitchen line when the pass
     | is watching the whole board.
     */
    $mine = function (Order $order) use ($station) {
        return $order->items->filter(fn ($line) => $line->isKitchenLine()
            && (! $station || (int) $line->kitchen_station_id === (int) $station->id));
    };

    $outstanding = [Order::PENDING, Order::CONFIRMED, Order::PREPARING, Order::READY];

    $columns = [];

    foreach ($outstanding as $rung) {
        $columns[$rung] = ['label' => $labels[$rung] ?? $rung, 'tickets' => collect()];
    }

    /*
     | A ticket sits in the column of its least-advanced line here. Six dishes
     | with one still waiting is a ticket that has not been started, whatever
     | the other five say - and putting it further along would let a cook lose
     | the one thing nobody has picked up.
     */
    foreach ($tickets as $ticket) {
        $lines = $mine($ticket)->filter(fn ($line) => in_array($line->kitchen_status, $outstanding, true));

        if ($lines->isEmpty()) {
            continue;
        }

        $at = $lines
            ->map(fn ($line) => array_search($line->kitchen_status, Order::KITCHEN_FLOW, true))
            ->filter(fn ($i) => $i !== false)
            ->min();

        $rung = Order::KITCHEN_FLOW[$at] ?? Order::PENDING;

        $columns[$rung]['tickets']->push(['order' => $ticket, 'lines' => $lines]);
    }
@endphp

<div class="kds-board"
     data-kds-board
     data-kds-latest="{{ $latest }}"
     data-kds-tickets="{{ $tickets->count() }}">

    @if ($tickets->isEmpty())
        <div class="kds-empty">
            <x-icon name="user-check" :size="42" />
            <h2>Nothing on the pass</h2>
            <p>
                @if ($station)
                    {{ $station->name }} is clear.
                @else
                    Every station is clear.
                @endif
                New tickets appear here on their own.
            </p>
        </div>
    @else
        @foreach ($columns as $rung => $column)
            <section class="kds-col" data-kds-col="{{ $rung }}">
                <header class="kds-col-head">
                    <h2>{{ $column['label'] }}</h2>
                    <span class="kds-col-count">{{ $column['tickets']->count() }}</span>
                </header>

                <div class="kds-col-body">
                    @forelse ($column['tickets'] as $entry)
                        @include('admin.kitchen._ticket', [
                            'order' => $entry['order'],
                            'lines' => $entry['lines'],
                            'rung' => $rung,
                        ])
                    @empty
                        <p class="kds-col-empty">—</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    @endif
</div>
