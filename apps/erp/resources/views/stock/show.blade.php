@extends('layouts.app')

@section('title', $item->name)

@php use App\Support\Units; $quote = Units::quote($item->last_cost_rate, $item->base_unit); @endphp

@section('page')
    <x-khana::page-header :title="$item->name"
        :subtitle="'Last cost ₹'.Units::rupees($quote['paise']).' per '.$quote['unit']"
        :crumbs="['Stock' => route('stock.index'), $item->name => null]">
        <x-slot:actions>
            @can('stock.manage')
                <a href="{{ route('items.edit', $item->id) }}" class="btn">Edit item</a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">Where it is</h2></div>

        @if ($levels->isEmpty())
            <x-khana::empty icon="package" title="None on hand" text="Nothing of this item is held at this outlet." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Store</th><th>Batch</th><th>Expires</th><th style="text-align:right">On hand</th><th style="text-align:right">Worth</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($levels as $level)
                            <tr>
                                <td>{{ $level->store->name }}</td>
                                <td>{{ $level->batch?->batch_no ?? '—' }}</td>
                                <td>
                                    @if ($level->batch?->expiry_date)
                                        {{ $level->batch->expiry_date->format('d M Y') }}
                                        @if ($level->batch->isExpired()) <span class="badge badge-danger">Expired</span> @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align:right">{{ $item->show($level->qty) }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($level->valuePaise()) }}</td>
                                <td class="col-action">
                                    @if ($level->batch)
                                        <a href="{{ route('labels.show', ['batch' => [$level->batch_id]]) }}" class="btn btn-sm btn-ghost">Label</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Last 50 movements</h2></div>

        @if ($movements->isEmpty())
            <x-khana::empty icon="list" title="No movements yet" />
        @else
            @include('movements.table', ['movements' => $movements, 'withItem' => false])
        @endif
    </div>
@endsection
