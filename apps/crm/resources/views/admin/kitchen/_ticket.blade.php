{{--
    One ticket card.

    Big, because it is read from two metres away by somebody holding a pan.
    Everything that is not the dish, the quantity and how long it has been
    waiting is either small or absent - there are no prices on this card and
    there never will be, because a KOT is a work order and not a receipt.
--}}

@php
    use App\Models\Order;

    $at = array_search($rung, Order::KITCHEN_FLOW, true);
    $next = $at === false ? null : (Order::KITCHEN_FLOW[$at + 1] ?? null);

    /*
     | The verb, not the noun. "Accept" is what a cook is about to do;
     | "Accepted" is where the ticket lands, and a button labelled with its
     | destination reads as a statement of fact somebody has to decode.
     */
    $verbs = [
        Order::CONFIRMED => 'Accept',
        Order::PREPARING => 'Start cooking',
        Order::READY => 'Ready',
        Order::SERVED => 'Served',
    ];

    $minutes = $order->kitchenMinutes();

    /*
     | The tightest window among the stations this card is showing. A ticket
     | with a drink and a kebab on it is late as soon as the bar is late -
     | which is the point, because the table gets the whole thing at once.
     */
    $allowed = $lines
        ->map(fn ($line) => $line->kitchenStation?->prepMinutes())
        ->filter()
        ->min() ?? 15;

    $tone = match (true) {
        $minutes >= $allowed => 'is-late',
        $minutes >= (int) ceil($allowed * 0.7) => 'is-warm',
        default => '',
    };

    // Lines on this ticket that belong to somebody else's board.
    $elsewhere = $order->items
        ->filter(fn ($line) => $line->isKitchenLine())
        ->reject(fn ($line) => $lines->contains('id', $line->id))
        ->reject(fn ($line) => $line->kitchen_status === Order::SERVED);

    $table = $order->tableSession?->table;
@endphp

<article class="kds-ticket {{ $tone }}"
         data-kds-ticket="{{ $order->id }}"
         data-placed="{{ optional($order->placed_at ?? $order->created_at)->timestamp }}"
         data-allowed="{{ $allowed }}">

    <header class="kds-ticket-head">
        <div>
            <span class="kds-no">{{ $order->order_number }}</span>

            <span class="kds-where">
                @if ($table)
                    {{ $table->floor?->name ? $table->floor->name.' · ' : '' }}Table {{ $table->name }}
                @else
                    {{ $order->typeLabel() }}@if ($order->customer) · {{ $order->customer->name }}@endif
                @endif
            </span>
        </div>

        {{--
            Ticking client-side between polls, so a screen nobody has touched
            for six seconds is not quietly six seconds out of date. The server
            writes the number too, for a browser with no JavaScript.
        --}}
        <span class="kds-timer" data-kds-timer>{{ $minutes }}m</span>
    </header>

    @if ($order->guest_name || $order->customer_note)
        <p class="kds-note">
            @if ($order->guest_name)<strong>{{ $order->guest_name }}</strong>@endif
            @if ($order->customer_note) {{ $order->customer_note }} @endif
        </p>
    @endif

    <ul class="kds-lines">
        @foreach ($lines as $line)
            <li class="kds-line {{ $line->kitchen_status === $rung ? '' : 'is-ahead' }}">
                <span class="kds-qty">{{ (int) $line->quantity }}</span>

                <div class="kds-what">
                    <span class="kds-dish">{{ $line->title() }}</span>

                    @if ($line->modifiers->isNotEmpty())
                        <span class="kds-mods">
                            {{ $line->modifiers->map(fn ($m) => $m->option_name)->implode(', ') }}
                        </span>
                    @endif

                    @if ($line->note)
                        <span class="kds-line-note">{{ $line->note }}</span>
                    @endif

                    {{--
                        Only worth saying on the pass's board, where one card
                        can hold two stations' work. On a station's own board
                        every line says the same thing.
                    --}}
                    @if (! $station && $line->kitchenStation)
                        <span class="kds-at">{{ $line->kitchenStation->code }}</span>
                    @endif
                </div>

                {{--
                    A line ahead of its ticket is already marked; bumping it
                    again is the one thing a cook must not be able to do by
                    brushing the screen.
                --}}
                @if ($line->kitchen_status !== $rung)
                    <span class="kds-line-state">{{ $line->kitchenStatusLabel() }}</span>
                @elseif ($next)
                    @allows('kitchen.tickets.advance')
                        <form method="POST" action="{{ route('admin.kitchen.bump-line', $line) }}"
                              data-ajax data-refresh-list>
                            @csrf
                            @method('PUT')
                            <button type="submit" class="kds-line-bump"
                                    title="{{ $verbs[$next] ?? 'Next' }} — this dish only"
                                    aria-label="{{ $verbs[$next] ?? 'Next' }} {{ $line->title() }}">
                                <x-icon name="chevron-right" :size="16" />
                            </button>
                        </form>
                    @endallows
                @endif
            </li>
        @endforeach
    </ul>

    @if ($elsewhere->isNotEmpty())
        <p class="kds-elsewhere">
            + {{ $elsewhere->sum(fn ($line) => (int) $line->quantity) }} at
            {{ $elsewhere->map(fn ($line) => $line->kitchenStation?->name ?? 'another station')->unique()->implode(', ') }}
        </p>
    @endif

    <footer class="kds-ticket-foot">
        @if ($next)
            @allows('kitchen.tickets.advance')
                <form method="POST" action="{{ route('admin.kitchen.bump', $order) }}"
                      data-ajax data-refresh-list class="kds-bump-form">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="to" value="{{ $next }}">
                    @if ($station)
                        <input type="hidden" name="station_id" value="{{ $station->id }}">
                    @endif
                    <button type="submit" class="kds-bump">{{ $verbs[$next] ?? 'Next' }}</button>
                </form>
            @endallows
        @endif

        <div class="kds-ticket-extras">
            @allows('kitchen.tickets.print')
                <a class="kds-extra"
                   href="{{ route('admin.kitchen.kot', array_filter(['order' => $order->id, 'station_id' => $station?->id])) }}"
                   target="_blank" rel="noopener"
                   title="Print the KOT slip">
                    <x-icon name="file" :size="15" />
                </a>
            @endallows

            @allows('kitchen.tickets.recall')
                @if ($at !== false && $at > 0)
                    <a class="kds-extra"
                       href="{{ route('admin.kitchen.recall-form', array_filter(['order' => $order->id, 'station_id' => $station?->id])) }}"
                       data-modal="{{ route('admin.kitchen.recall-form', array_filter(['order' => $order->id, 'station_id' => $station?->id])) }}"
                       data-modal-title="Send {{ $order->order_number }} back"
                       data-modal-sub="This rewrites the times the prep report is built from"
                       title="Send this ticket back a stage">
                        <x-icon name="trend-down" :size="15" />
                    </a>
                @endif
            @endallows
        </div>
    </footer>
</article>
