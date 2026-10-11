@extends('layouts.app')

@section('title', 'Stock on hand')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Stock on hand" :subtitle="'Worth ₹'.Units::rupees($worth).' at cost'">
        <x-slot:actions>
            @can('count')
                <a href="{{ Route::has('counts.create') ? route('counts.create') : '#' }}" class="btn">Count stock</a>
            @endcan
            @can('receive')
                <a href="{{ Route::has('receipts.create') ? route('receipts.create') : '#' }}" class="btn btn-primary">
                    <x-khana::icon name="plus" :size="16" /> Receive goods
                </a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-khana::icon name="search" :size="15" />
                <label for="q" class="sr-only">Search stock</label>
                <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Search item, code or category…">
            </div>

            <div class="list-filters">
                <label for="store" class="sr-only">Store</label>
                <select id="store" name="store" onchange="this.form.submit()">
                    <option value="">All stores</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected($storeId === $store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>

                <label for="show" class="sr-only">Show</label>
                <select id="show" name="show" onchange="this.form.submit()">
                    <option value="all" @selected($show === 'all')>Every item</option>
                    <option value="held" @selected($show === 'held')>Only what is held</option>
                    <option value="low" @selected($show === 'low')>Running low ({{ $low }})</option>
                    <option value="negative" @selected($show === 'negative')>Gone negative ({{ $negative }})</option>
                </select>
            </div>
        </form>

        @if ($rows->isEmpty())
            <x-khana::empty icon="package" title="Nothing to show"
                text="{{ $search !== '' || $show !== 'all' ? 'No item matches that.' : 'Add the things you buy - paneer, oil, cola - and receive your first delivery.' }}">
                @can('stock.manage')
                    <a href="{{ route('items.create') }}" class="btn btn-primary">Add a stock item</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Item</th><th>Category</th><th style="text-align:right">On hand</th><th style="text-align:right">Worth</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $item = $row['item']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('stock.show', $item->id) }}"><strong>{{ $item->name }}</strong></a>
                                    @if ($item->is_prepared) <span class="badge badge-info">Made in-house</span> @endif
                                </td>
                                <td class="text-muted">{{ $item->category ?? '—' }}</td>
                                <td style="text-align:right">
                                    {{ $item->show($row['qty']) }}
                                    @if ($row['qty'] < 0)
                                        <span class="badge badge-danger">Negative</span>
                                    @elseif ($item->reorder_qty > 0 && $row['qty'] < $item->reorder_qty)
                                        <span class="badge badge-warning">Low</span>
                                    @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($row['value']) }}</td>
                                <td class="col-action">
                                    <a href="{{ route('stock.show', $item->id) }}" class="btn btn-sm btn-ghost">History</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
