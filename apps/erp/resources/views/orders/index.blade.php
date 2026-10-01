@extends('layouts.app')

@section('title', 'Purchase orders')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Purchase orders" subtitle="What you have asked suppliers for">
        <x-slot:actions>
            @can('purchasing.manage')
                <a href="{{ route('orders.create') }}" class="btn btn-primary">
                    <x-khana::icon name="plus" :size="16" /> New order
                </a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        @if ($orders->isEmpty())
            <x-khana::empty icon="cart" title="No orders yet"
                text="Orders are optional - a delivery can be received without one. Write one when you want the delivery checked against what you asked for.">
                @can('purchasing.manage')
                    <a href="{{ route('orders.create') }}" class="btn btn-primary">New order</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Order</th><th>Supplier</th><th>Ordered</th><th>Expected</th><th>Items</th><th style="text-align:right">Value</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><a href="{{ route('orders.show', $order->id) }}"><strong>{{ $order->number }}</strong></a></td>
                                <td>{{ $order->supplier->name }}</td>
                                <td>{{ $order->ordered_on->format('d M Y') }}</td>
                                <td>{{ $order->expected_on?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $order->lines_count }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($order->total_paise) }}</td>
                                <td><span class="badge badge-{{ $order->statusTone() }}">{{ $order->statusLabel() }}</span></td>
                                <td class="col-action">
                                    @if ($order->isOpen())
                                        @can('receive')
                                            <a href="{{ route('receipts.create', ['order' => $order->id]) }}" class="btn btn-sm">Receive</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $orders])
        @endif
    </div>
@endsection
