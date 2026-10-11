@extends('layouts.app')

@section('title', 'Stock items')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Stock items" subtitle="What you buy, hold and cook with">
        <x-slot:actions>
            @can('stock.manage')
                <a href="{{ route('items.create') }}" class="btn btn-primary">
                    <x-khana::icon name="plus" :size="16" /> Add item
                </a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-khana::icon name="search" :size="15" />
                <label for="q" class="sr-only">Search items</label>
                <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Search item, code or category…">
            </div>
        </form>

        @if ($items->isEmpty())
            <x-khana::empty icon="package" title="No stock items yet"
                text="Add the things you buy - paneer, oil, cola - before you receive your first delivery.">
                @can('stock.manage')
                    <a href="{{ route('items.create') }}" class="btn btn-primary">Add a stock item</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Item</th><th>Category</th><th>Kept in</th><th>Bought as</th><th style="text-align:right">Last cost</th><th>Tell me below</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php $quote = Units::quote($item->last_cost_rate, $item->base_unit); @endphp
                            <tr @unless ($item->is_active) style="opacity:.55" @endunless>
                                <td>
                                    <strong>{{ $item->name }}</strong>
                                    @if ($item->is_prepared) <span class="badge badge-info">Made in-house</span> @endif
                                    @unless ($item->is_active) <span class="badge">Off</span> @endunless
                                    @if ($item->code) <div class="text-xs text-muted">{{ $item->code }}</div> @endif
                                </td>
                                <td class="text-muted">{{ $item->category ?? '—' }}</td>
                                <td>{{ $item->base_unit }}@if ($item->track_expiry) <span class="text-xs text-muted">· expiry tracked</span> @endif</td>
                                <td class="text-sm">{{ $item->packs->pluck('name')->implode(', ') ?: '—' }}</td>
                                <td style="text-align:right">
                                    @if ($item->last_cost_rate > 0)
                                        &#8377;{{ Units::rupees($quote['paise']) }} / {{ $quote['unit'] }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $item->reorder_qty > 0 ? $item->show($item->reorder_qty) : '—' }}</td>
                                <td class="col-action">
                                    @can('stock.manage')
                                        <a href="{{ route('items.edit', $item->id) }}" class="btn btn-sm btn-ghost">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $items])
        @endif
    </div>
@endsection
