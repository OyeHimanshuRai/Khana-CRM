@extends('layouts.app')

@section('title', 'Goods received')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Goods received" subtitle="Every delivery taken in">
        <x-slot:actions>
            @can('receive')
                <a href="{{ route('receipts.create') }}" class="btn btn-primary">
                    <x-khana::icon name="plus" :size="16" /> Receive goods
                </a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        @if ($receipts->isEmpty())
            <x-khana::empty icon="truck" title="Nothing received yet" text="When a delivery arrives, record it here: the stock goes up and the supplier's bill is raised.">
                @can('receive')
                    <a href="{{ route('receipts.create') }}" class="btn btn-primary">Receive goods</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Receipt</th><th>Date</th><th>Supplier</th><th>Into</th><th>Items</th><th style="text-align:right">Total</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td><a href="{{ route('receipts.show', $receipt->id) }}"><strong>{{ $receipt->number }}</strong></a></td>
                                <td>{{ $receipt->received_on->format('d M Y') }}</td>
                                <td>
                                    {{ $receipt->supplier?->name ?? '—' }}
                                    @if ($receipt->bill_no) <div class="text-xs text-muted">Bill {{ $receipt->bill_no }}</div> @endif
                                </td>
                                <td>{{ $receipt->store?->name }}</td>
                                <td>{{ $receipt->lines_count }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($receipt->total_paise) }}</td>
                                <td class="col-action"><a href="{{ route('receipts.show', $receipt->id) }}" class="btn btn-sm btn-ghost">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $receipts])
        @endif
    </div>
@endsection
