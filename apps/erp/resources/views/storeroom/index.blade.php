{{-- A list of one kind of store-room paperwork: issues, wastage or production. --}}
@extends('layouts.app')

@section('title', $title)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$title" :subtitle="$subtitle">
        <x-slot:actions>
            <a href="{{ route($createRoute) }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> {{ $createLabel }}
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        @if ($documents->isEmpty())
            <x-khana::empty icon="list" title="Nothing recorded yet">
                <a href="{{ route($createRoute) }}" class="btn btn-primary">{{ $createLabel }}</a>
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Number</th><th>Date</th>
                            <th>{{ $kind === 'issue' ? 'From → to' : 'Store' }}</th>
                            <th>What</th>
                            <th style="text-align:right">Worth</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td><strong>{{ $document->number }}</strong></td>
                                <td>{{ $document->on_date->format('d M Y') }}</td>
                                <td>{{ $document->store?->name }}@if ($document->toStore) → {{ $document->toStore->name }}@endif</td>
                                <td class="text-sm">
                                    @if ($kind === 'production')
                                        @php $made = $document->lines->firstWhere('qty', '>', 0); @endphp
                                        {{ $made ? $made->item->show($made->qty).' of '.$made->item->name : '' }}
                                        {{-- Made as a batch, so the tubs can be labelled. --}}
                                        @if ($made && $made->batch_id > 0 && auth()->user()->can('stock.view'))
                                            <a href="{{ route('labels.show', ['document' => $document->id]) }}" class="text-xs" style="margin-left:6px">Print labels</a>
                                        @endif
                                    @else
                                        {{ $document->lines->take(4)->map(fn ($line) => $line->item->name.' '.$line->item->show(abs($line->qty)))->implode(', ') }}
                                        @if ($document->lines->count() > 4) and {{ $document->lines->count() - 4 }} more @endif
                                    @endif
                                    @if ($document->note) <div class="text-xs text-muted">{{ $document->note }}</div> @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($document->value_paise) }}</td>
                                <td class="text-sm text-muted">{{ $document->user?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $documents])
        @endif
    </div>
@endsection
