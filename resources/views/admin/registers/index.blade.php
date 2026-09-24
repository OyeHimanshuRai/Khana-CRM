@extends('admin.layouts.app')

@section('title', 'Day Close')

@section('content')
    <x-page-header
        title="Cash Register / Day Close"
        subtitle="Open the till with a float, and reconcile it against the payments ledger at the end of the day."
        :crumbs="['POS' => null, 'Day Close' => null]"
    >
        <x-slot:actions>
            @allows('pos.registers.export')
                <a class="btn btn-sm" href="{{ route('admin.registers.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    {{--
        A day nobody counted.

        Shown above everything else because it blocks opening today's till -
        see CashRegisterService::open() - and because nothing else in the
        system will ever mention it again. A register left open simply sits
        there with no expected figure, no counted figure and no variance,
        while the drawer it was meant to reconcile moves on.
    --}}
    @if ($shop && $stale)
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>{{ $stale->business_date->format('d M Y') }} was never closed.</strong>
            That register is still open and has never been counted, so today's cannot be
            opened on top of it.
            <a href="{{ route('admin.registers.show', $stale) }}">Close {{ $stale->business_date->format('d M') }} first</a>.
        </div>
    @endif

    @if (! $shop)
        <div class="pos-warning" style="margin-bottom:16px">
            Choose a single shop to open or view its register - a till belongs to one shop at a time.
        </div>
    @elseif ($today)
        <div class="card" style="margin-bottom:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Today, {{ today()->format('d M Y') }}</div>
                    <div class="text-xs text-muted">{{ $shop->name }}</div>
                </div>
                <span class="badge {{ $today->statusTone() ? 'badge-'.$today->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $today->statusLabel() }}
                </span>
            </div>
            <div class="card-body">
                <a class="btn btn-primary btn-sm" href="{{ route('admin.registers.show', $today) }}">
                    {{ $today->isOpen() ? 'Close the register' : 'View' }}
                </a>
            </div>
        </div>
    @elseif (! $stale)
        @allows('pos.registers.create')
            <div class="card" style="margin-bottom:16px">
                <div class="card-header">
                    <div class="card-title">Open today's register</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.registers.store') }}" data-ajax data-redirect-delay="500"
                          style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                        @csrf
                        <div class="field" style="margin:0;max-width:220px">
                            <label for="reg-float">Opening float</label>
                            <input id="reg-float" type="number" step="0.01" name="opening_float" class="form-control"
                                   value="0" min="0" required aria-invalid="false">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="wallet" :size="15" /> Open register
                        </button>
                    </form>
                </div>
            </div>
        @endallows
    @endif

    <div class="card" data-ajax-list="{{ route('admin.registers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.registers.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.registers._list')
        </div>
    </div>
@endsection
