{{--
    Batch labels - a page made to be printed, so it stands alone: no
    sidebar, no shared stylesheet, nothing a printer could choke on.

    The labels are cut into pages here rather than left to the browser,
    because a sticker sheet has exactly so many to a page and a label split
    across two is two wasted. One .page is one sheet, or one label of a roll.

    Plain black on white: a thermal printer has no other colour.
--}}
@php
    // Type that fits the label: a 25 mm roll and a 50 mm one share a layout.
    [$big, $small, $pad] = match (true) {
        $layout['height'] >= 45 => ['15pt', '10pt', '4mm 5mm'],
        $layout['height'] >= 35 => ['11.5pt', '8pt', '2.5mm 4mm'],
        default => ['9pt', '6.5pt', '1.5mm 2.5mm'],
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Batch labels &middot; Khana ERP</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            color: #111;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        }

        .bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid #cbd5e1;
            font-size: 13px;
        }

        .bar select, .bar input, .bar button, .bar a {
            font: inherit;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
        }

        .bar input { width: 64px; }
        .bar button { cursor: pointer; }
        .bar .is-primary { background: #0f172a; border-color: #0f172a; color: #fff; }
        .bar-note { flex-basis: 100%; text-align: center; color: #64748b; }

        .page {
            display: grid;
            grid-template-columns: repeat({{ $layout['across'] }}, {{ $layout['width'] }}mm);
            grid-auto-rows: {{ $layout['height'] }}mm;
            width: {{ $layout['across'] * $layout['width'] }}mm;
            margin: 16px auto;
            background: #fff;
        }

        .label {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            width: {{ $layout['width'] }}mm;
            height: {{ $layout['height'] }}mm;
            padding: {{ $pad }};
            overflow: hidden;
            outline: 1px dashed #cbd5e1;
            outline-offset: -1px;
            font-size: {{ $small }};
            line-height: 1.25;
        }

        .label-item {
            font-size: {{ $big }};
            font-weight: 700;
            line-height: 1.15;
            max-height: 2.3em;
            overflow: hidden;
        }

        .label-use {
            font-size: {{ $big }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .label-foot {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
        }

        @media print {
            html, body { background: #fff; }
            .no-print { display: none !important; }
            .page { margin: 0; page-break-after: always; break-after: page; }
            .page:last-child { page-break-after: auto; break-after: auto; }
            .label { outline: 0; }

            @page { size: {{ $layout['page'] }}; margin: 0; }
        }
    </style>
</head>
<body>
    <form method="GET" class="bar no-print">
        @foreach ($keep as $name => $value)
            @foreach ((array) $value as $one)
                <input type="hidden" name="{{ $name }}{{ is_array($value) ? '[]' : '' }}" value="{{ $one }}">
            @endforeach
        @endforeach

        <label for="paper">Paper</label>
        <select id="paper" name="paper" onchange="this.form.submit()">
            @foreach ($papers as $key => $option)
                <option value="{{ $key }}" @selected($key === $paper)>{{ $option['name'] }}</option>
            @endforeach
        </select>

        <label for="copies">Copies of each</label>
        <input id="copies" name="copies" type="number" min="1" max="50" value="{{ $copies }}" onchange="this.form.submit()">

        <noscript><button type="submit">Show</button></noscript>

        <button type="button" class="is-primary" onclick="window.print()">Print {{ $count }} {{ $count === 1 ? 'label' : 'labels' }}</button>
        <a href="{{ url()->previous() !== url()->full() ? url()->previous() : route('stock.index') }}">Back</a>

        <div class="bar-note">
            {{ $batches->count() }} {{ $batches->count() === 1 ? 'batch' : 'batches' }}.
            In the print window, set margins to "None" and scale to 100% so the labels land on the stickers.
        </div>
    </form>

    @foreach ($pages as $page)
        <div class="page">
            @foreach ($page as $label)
                <div class="label">
                    <div>
                        <div class="label-item">{{ $label['item'] }}</div>
                        <div>Batch {{ $label['batch_no'] }}</div>
                    </div>

                    @if ($label['expiry'])
                        <div class="label-use">Use by {{ $label['expiry']->format('d M Y') }}</div>
                    @endif

                    <div class="label-foot">
                        <span>
                            @if ($label['made_on'])
                                Made {{ $label['made_on']->format('d M Y') }}
                            @elseif ($label['since'])
                                Received {{ $label['since']->format('d M Y') }}
                            @endif
                        </span>
                        <span>{{ $outletName }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
