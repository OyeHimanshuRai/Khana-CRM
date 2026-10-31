<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Table QR Codes</title>

    <link rel="stylesheet"
          href="{{ asset('assets/css/qr-sheet.css') }}?v={{ filemtime(public_path('assets/css/qr-sheet.css')) }}">

    <style>
        /* The one thing that varies per print run. */
        :root { --card: {{ $spec['card'] }}mm; }
    </style>
</head>
{{--
    Prints itself on load, the same as the barcode sheet. The operator came
    here from a "Print sheet" button in a new tab; making them reach for
    Ctrl+P after that is a step with no decision in it.
--}}
<body class="qr-body" onload="window.print()">

{{--
    Screen-only controls. `qr-toolbar` is display:none in the print
    stylesheet, so none of this reaches the paper.
--}}
<div class="qr-toolbar">
    <div>
        <strong>{{ $tables->count() }}</strong> table{{ $tables->count() === 1 ? '' : 's' }}
        · {{ $spec['label'] }}
    </div>

    <div class="qr-toolbar-actions">
        @foreach ($sizes as $key => $option)
            <a class="qr-size {{ $key === $size ? 'is-on' : '' }}"
               href="{{ route('admin.qr.sheet', array_merge(request()->only('floor_id', 'q'), ['size' => $key])) }}">
                {{ $option['label'] }}
            </a>
        @endforeach

        <button type="button" onclick="window.print()">Print</button>
    </div>
</div>

@if ($tables->isEmpty())
    <div class="qr-empty">
        <h1>Nothing to print</h1>
        <p>
            No table in this selection has a QR code. Issue codes from the QR screen,
            then come back here.
        </p>
    </div>
@else
    <div class="qr-sheet">
        @foreach ($tables as $table)
            <div class="qr-card">
                <div class="qr-card-head">
                    <div class="qr-card-shop">{{ $shop?->name ?: $company }}</div>
                    <div class="qr-card-table">Table {{ $table->name }}</div>
                    <div class="qr-card-area">{{ $table->floor?->name }}</div>
                </div>

                {{--
                    White behind the code whatever the page is, because a QR
                    on a tinted ground is a QR that fails on a cheap camera in
                    a dim room - which is every restaurant at dinner.
                --}}
                <div class="qr-card-code">{!! $codes[$table->id] !!}</div>

                <div class="qr-card-foot">
                    <div class="qr-card-call">Scan to see the menu &amp; order</div>
                    {{-- The code, small: it is how a peeling sticker is
                         matched back to the right table. --}}
                    <div class="qr-card-ref">{{ $table->code }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif

</body>
</html>
