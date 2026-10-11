@extends('layouts.app')

@section('title', $title)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$title" :subtitle="$subtitle">
        <x-slot:actions>
            <button type="button" class="btn" onclick="window.print()">Print</button>
            <a class="btn" href="{{ route('reports.export', ['report' => $report] + ($periodic ? ['from' => $from, 'to' => $to] : [])) }}">Download CSV</a>
        </x-slot:actions>
    </x-khana::page-header>

    @if ($periodic)
        <form method="GET" class="card" style="margin-bottom:18px">
            <div class="list-toolbar">
                <div class="list-filters">
                    <label for="from" class="text-sm text-muted">From</label>
                    <input id="from" type="date" name="from" value="{{ $from }}" onchange="this.form.submit()">
                    <label for="to" class="text-sm text-muted">to</label>
                    <input id="to" type="date" name="to" value="{{ $to }}" onchange="this.form.submit()">
                    <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
                </div>
            </div>
        </form>
    @endif

    @include('reports.'.$report, ['data' => $data])
@endsection
