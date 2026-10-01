@extends('layouts.app')

@section('title', $receipt->number)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$receipt->number"
        :subtitle="'Received '.$receipt->received_on->format('d M Y').' into '.$receipt->store->name.($receipt->user ? ' by '.$receipt->user->name : '')"
        :crumbs="['Goods received' => route('receipts.index'), $receipt->number => null]">
        <x-slot:actions>
            <button type="button" class="btn" onclick="window.print()">Print</button>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Supplier</div>
                <div class="stat-value" style="font-size:16px">
                    @if ($receipt->supplier)
                        <a href="{{ route('suppliers.show', $receipt->supplier_id) }}">{{ $receipt->supplier->name }}</a>
                    @else
                        Cash purchase
                    @endif
                </div>
                @if ($receipt->bill_no) <div class="text-xs text-muted">Their bill {{ $receipt->bill_no }}</div> @endif
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">&#8377;{{ Units::rupees($receipt->total_paise) }}</div>
                <div class="text-xs text-muted">&#8377;{{ Units::rupees($receipt->net_paise) }} + &#8377;{{ Units::rupees($receipt->tax_paise) }} tax</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Bill</div>
                @if ($receipt->bill)
                    <div class="stat-value" style="font-size:16px">
                        <span class="badge badge-{{ $receipt->bill->status === 'paid' ? 'success' : 'warning' }}">{{ $receipt->bill->status === 'paid' ? 'Paid' : 'Owed' }}</span>
                    </div>
                    <div class="text-xs text-muted">
                        &#8377;{{ Units::rupees($receipt->bill->duePaise()) }} due{{ $receipt->bill->due_on ? ' by '.$receipt->bill->due_on->format('d M Y') : '' }}
                    </div>
                @else
                    <div class="stat-value" style="font-size:16px">None raised</div>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Batch</th><th style="text-align:right">Received</th><th style="text-align:right">Amount</th><th style="text-align:right">Tax</th><th style="text-align:right">Cost in stock</th></tr>
                </thead>
                <tbody>
                    @foreach ($receipt->lines as $line)
                        @php $quote = Units::quote($line->cost_rate, $line->item->base_unit); @endphp
                        <tr>
                            <td><a href="{{ route('stock.show', $line->stock_item_id) }}">{{ $line->item->name }}</a></td>
                            <td>
                                @if ($line->batch)
                                    {{ $line->batch->batch_no }}
                                    @if ($line->batch->expiry_date) <div class="text-xs text-muted">Expires {{ $line->batch->expiry_date->format('d M Y') }}</div> @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:right">{{ $line->item->show($line->qty) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($line->net_paise) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($line->tax_paise) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($quote['paise']) }} / {{ $quote['unit'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($receipt->notes)
            <div class="card-footer text-sm text-muted">{{ $receipt->notes }}</div>
        @endif
    </div>
@endsection
