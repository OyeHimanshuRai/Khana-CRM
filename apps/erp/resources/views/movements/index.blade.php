@extends('layouts.app')

@section('title', 'Stock movements')

@section('page')
    <x-khana::page-header title="Movements" subtitle="Every change to stock, and why" />

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="type" class="sr-only">What happened</label>
                <select id="type" name="type" onchange="this.form.submit()">
                    <option value="">Everything</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="from" class="sr-only">From</label>
                <input id="from" type="date" name="from" value="{{ $from }}" onchange="this.form.submit()">

                <label for="to" class="sr-only">To</label>
                <input id="to" type="date" name="to" value="{{ $to }}" onchange="this.form.submit()">
            </div>
        </form>

        @if ($movements->isEmpty())
            <x-khana::empty icon="list" title="No movements" text="Nothing has moved in that period." />
        @else
            @include('movements.table', ['movements' => $movements, 'withItem' => true])
            @include('partials.pager', ['rows' => $movements])
        @endif
    </div>
@endsection
