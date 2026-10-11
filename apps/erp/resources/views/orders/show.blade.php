@extends('layouts.app')

@section('title', $order->number)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$order->number"
        :subtitle="'To '.$order->supplier->name.', '.$order->ordered_on->format('d M Y')"
        :crumbs="['Orders' => route('orders.index'), $order->number => null]">
        <x-slot:actions>
            <button type="button" class="btn" onclick="window.print()">Print</button>

            @if ($order->status === 'open')
                @can('purchasing.manage')
                    <form method="POST" action="{{ route('orders.cancel', $order->id) }}" onsubmit="return confirm('Cancel this order?')">
                        @csrf @method('PUT')
                        <button type="submit" class="btn btn-ghost">Cancel order</button>
                    </form>
                @endcan
            @endif

            @if ($order->isOpen())
                @can('receive')
                    <a href="{{ route('receipts.create', ['order' => $order->id]) }}" class="btn btn-primary">Receive this delivery</a>
                @endcan
            @endif
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h2 class="card-title">What was ordered</h2>
            <span class="badge badge-{{ $order->statusTone() }}">{{ $order->statusLabel() }}</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th style="text-align:right">Ordered</th><th style="text-align:right">Received</th><th style="text-align:right">Still to come</th><th style="text-align:right">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($order->lines as $line)
                        <tr>
                            <td>{{ $line->item->name }}</td>
                            <td style="text-align:right">{{ $line->item->show($line->qty) }}</td>
                            <td style="text-align:right">{{ $line->item->show($line->received_qty) }}</td>
                            <td style="text-align:right">{{ $line->outstanding() > 0 ? $line->item->show($line->outstanding()) : '—' }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($line->amount_paise) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="4" style="text-align:right"><strong>Total before tax</strong></td><td style="text-align:right"><strong>&#8377;{{ Units::rupees($order->total_paise) }}</strong></td></tr>
                </tfoot>
            </table>
        </div>
        @if ($order->notes) <div class="card-footer text-sm text-muted">{{ $order->notes }}</div> @endif
    </div>

    @if ($order->receipts->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="card-title">Deliveries against this order</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($order->receipts as $receipt)
                            <tr>
                                <td><a href="{{ route('receipts.show', $receipt->id) }}">{{ $receipt->number }}</a></td>
                                <td>{{ $receipt->received_on->format('d M Y') }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($receipt->total_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
