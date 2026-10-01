@extends('layouts.app')

@section('title', 'Stock counts')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Stock counts" subtitle="The shelf, checked against the books">
        <x-slot:actions>
            <a href="{{ route('counts.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> Count stock
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        @if ($counts->isEmpty())
            <x-khana::empty icon="list" title="No counts yet"
                text="Your first count is your opening stock. After that, a weekly count shows what went missing.">
                <a href="{{ route('counts.create') }}" class="btn btn-primary">Count stock</a>
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Count</th><th>Date</th><th>Store</th><th>Items counted</th><th style="text-align:right">Difference</th><th>By</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($counts as $count)
                            <tr>
                                <td><a href="{{ route('counts.show', $count->id) }}"><strong>{{ $count->number }}</strong></a></td>
                                <td>{{ $count->on_date->format('d M Y') }}</td>
                                <td>{{ $count->store?->name }}</td>
                                <td>{{ $count->lines_count }}</td>
                                <td style="text-align:right;{{ $count->value_paise < 0 ? 'color:var(--danger)' : '' }}">
                                    {{ $count->value_paise > 0 ? '+' : '' }}&#8377;{{ Units::rupees($count->value_paise) }}
                                </td>
                                <td class="text-sm text-muted">{{ $count->user?->name }}</td>
                                <td class="col-action"><a href="{{ route('counts.show', $count->id) }}" class="btn btn-sm btn-ghost">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $counts])
        @endif
    </div>
@endsection
