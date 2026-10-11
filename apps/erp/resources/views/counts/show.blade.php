@extends('layouts.app')

@section('title', $count->number)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$count->number"
        :subtitle="$count->store->name.', '.$count->on_date->format('d M Y').($count->user ? ' by '.$count->user->name : '')"
        :crumbs="['Counts' => route('counts.index'), $count->number => null]">
        <x-slot:actions>
            <button type="button" class="btn" onclick="window.print()">Print</button>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Items counted</div>
                <div class="stat-value">{{ $count->lines->count() }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Did not match</div>
                <div class="stat-value">{{ $count->lines->where('qty', '!=', 0)->count() }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Difference at cost</div>
                <div class="stat-value" style="{{ $count->value_paise < 0 ? 'color:var(--danger)' : '' }}">
                    {{ $count->value_paise > 0 ? '+' : '' }}&#8377;{{ Units::rupees($count->value_paise) }}
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Batch</th><th style="text-align:right">Books said</th><th style="text-align:right">Counted</th><th style="text-align:right">Difference</th><th style="text-align:right">Worth</th></tr>
                </thead>
                <tbody>
                    @foreach ($count->lines->sortBy(fn ($line) => $line->value_paise) as $line)
                        <tr>
                            <td><a href="{{ route('stock.show', $line->stock_item_id) }}">{{ $line->item->name }}</a></td>
                            <td>{{ $line->batch?->batch_no ?? '—' }}</td>
                            <td style="text-align:right">{{ $line->item->show($line->expected_qty) }}</td>
                            <td style="text-align:right">{{ $line->item->show($line->counted_qty) }}</td>
                            <td style="text-align:right;{{ $line->qty < 0 ? 'color:var(--danger)' : '' }}">
                                @if ($line->qty === 0)
                                    <span class="text-muted">Matched</span>
                                @else
                                    {{ $line->qty > 0 ? '+' : '' }}{{ $line->item->show($line->qty) }}
                                @endif
                            </td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($line->value_paise) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($count->note) <div class="card-footer text-sm text-muted">{{ $count->note }}</div> @endif
    </div>
@endsection
