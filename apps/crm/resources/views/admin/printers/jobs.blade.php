@extends('admin.layouts.app')

@section('title', 'Print Log')

@section('content')
    <x-page-header
        title="Print Log"
        subtitle="What was printed, by whom, and what never arrived"
        :crumbs="['Settings' => null, 'Printers' => route('admin.printers.index'), 'Print Log' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.printers.index') }}">
                <x-icon name="settings" :size="15" /> Printers
            </a>
        </x-slot:actions>
    </x-page-header>

    {{--
        §6 asks for "KOT print and reprint with audit trail", and the audit is
        about the reprint rather than the print. A first KOT is ordinary. A
        second copy of the same KOT is how a dish gets made twice — so the two
        filters on this screen are the two questions worth asking.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.printers.jobs') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label class="check">
                    <input type="checkbox" name="reprints" value="1" @checked($reprints) data-ajax-filter>
                    <span>Reprints only</span>
                </label>

                <label class="check">
                    <input type="checkbox" name="failed" value="1" @checked($failed) data-ajax-filter>
                    <span>Failed only</span>
                </label>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.printers.jobs') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.printers._jobs')
        </div>
    </div>
@endsection
