<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KOT {{ $order->order_number }}</title>

    <link rel="stylesheet"
          href="{{ asset('assets/css/kot.css') }}?v={{ filemtime(public_path('assets/css/kot.css')) }}">
</head>
{{--
    Prints itself on load, the same as the QR sheet and the barcode sheet: the
    operator came here from a print button in a new tab, and making them reach
    for Ctrl+P after that is a step with no decision in it.

    There are no prices anywhere on this slip and there never will be. A KOT is
    a work order; one that carried totals would be handed to a guest by mistake
    about once a month.
--}}
<body class="kot-body" onload="window.print()">

<div class="kot">
    <header class="kot-head">
        <h1>{{ $order->order_number }}</h1>

        <p class="kot-where">
            @if ($order->tableSession?->table)
                {{ $order->tableSession->table->floor?->name }}
                · Table {{ $order->tableSession->table->name }}
            @else
                {{ $order->typeLabel() }}
            @endif
        </p>

        @if ($station)
            <p class="kot-station">{{ $station->name }} ({{ $station->code }})</p>
        @endif

        <p class="kot-when">
            {{ optional($order->placed_at ?? $order->created_at)->format('d M Y, g:i a') }}
        </p>

        @if ($order->guest_name)
            <p class="kot-guest">{{ $order->guest_name }}</p>
        @endif
    </header>

    <table class="kot-lines">
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="kot-qty">{{ (int) $line->quantity }}</td>
                    <td>
                        <span class="kot-dish">{{ $line->title() }}</span>

                        @if ($line->modifiers->isNotEmpty())
                            <span class="kot-mods">
                                {{ $line->modifiers->map(fn ($m) => $m->option_name)->implode(', ') }}
                            </span>
                        @endif

                        @if ($line->note)
                            <span class="kot-note">** {{ $line->note }}</span>
                        @endif

                        {{-- Only on a slip printed for the whole ticket, where
                             one page can carry two stations' work. --}}
                        @if (! $station && $line->kitchenStation)
                            <span class="kot-at">{{ $line->kitchenStation->code }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="kot-dish">Nothing on this ticket here.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($order->customer_note)
        <p class="kot-order-note">{{ $order->customer_note }}</p>
    @endif

    <footer class="kot-foot">
        {{ $lines->sum(fn ($line) => (int) $line->quantity) }}
        item{{ $lines->sum(fn ($line) => (int) $line->quantity) === 1 ? '' : 's' }}
        · reprinted {{ now()->format('g:i a') }}
    </footer>
</div>

</body>
</html>
