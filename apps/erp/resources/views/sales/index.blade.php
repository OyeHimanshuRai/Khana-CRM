@extends('layouts.app')

@section('title', 'Sales')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Sales" subtitle="The bills ERP has heard about">
        <x-slot:actions>
            @can('sales.import')
                <a href="{{ route('sales.import') }}" class="btn">Import a sales file</a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="source" class="sr-only">From</label>
                <select id="source" name="source" onchange="this.form.submit()">
                    <option value="">Every source</option>
                    @foreach ($sources as $key => $label)
                        <option value="{{ $key }}" @selected($source === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="from" class="sr-only">From date</label>
                <input id="from" type="date" name="from" value="{{ $from }}" onchange="this.form.submit()">

                <label for="to" class="sr-only">To date</label>
                <input id="to" type="date" name="to" value="{{ $to }}" onchange="this.form.submit()">
            </div>
        </form>

        @if ($sales->isEmpty())
            <x-khana::empty icon="chart" title="No sales here yet"
                text="With Restro One CRM, every bill arrives by itself. With other billing software, import a sales file - or have it post sales through the API.">
                @can('sales.import')
                    <a href="{{ route('sales.import') }}" class="btn btn-primary">Import a sales file</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Bill</th><th>Date</th><th>From</th><th>Lines</th><th style="text-align:right">Total</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            <tr @if ($sale->isCancelled()) style="opacity:.6" @endif>
                                <td>
                                    <a href="{{ route('sales.show', $sale->id) }}"><strong>{{ $sale->invoice_no }}</strong></a>
                                    @if ($sale->isCancelled()) <span class="badge badge-danger">Cancelled</span> @endif
                                </td>
                                <td>{{ $sale->business_date->format('d M Y') }}</td>
                                <td class="text-sm">{{ $sources[$sale->source] ?? $sale->source }}</td>
                                <td>{{ $sale->lines_count }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($sale->grand_paise) }}</td>
                                <td class="col-action"><a href="{{ route('sales.show', $sale->id) }}" class="btn btn-sm btn-ghost">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $sales])
        @endif
    </div>
@endsection
