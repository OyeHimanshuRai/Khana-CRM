@extends('layouts.app')

@section('title', 'Prepared items')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Prepared items" subtitle="What the kitchen makes for itself: a gravy base, a dough, a marinade">
        <x-slot:actions>
            @can('recipes.manage')
                <a href="{{ route('production.create') }}" class="btn btn-primary">Make a batch</a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        @if ($items->isEmpty())
            <x-khana::empty icon="package" title="Nothing is made in-house yet"
                text="Tick &quot;Made in-house&quot; on a stock item and it appears here for its recipe. Dishes then use it like any other ingredient.">
                @can('stock.manage')
                    <a href="{{ route('items.create') }}" class="btn btn-primary">Add a stock item</a>
                @endcan
            </x-khana::empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Item</th><th>One batch makes</th><th>From</th><th style="text-align:right">A batch costs</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php $recipe = $recipes[$item->id] ?? null; @endphp
                            <tr>
                                <td><strong>{{ $item->name }}</strong></td>
                                <td>{{ $recipe ? $item->show($recipe->yield_qty) : '—' }}</td>
                                <td class="text-sm">
                                    @if ($recipe)
                                        {{ $recipe->lines->map(fn ($line) => $line->item?->name)->filter()->implode(', ') }}
                                    @else
                                        <span class="badge badge-warning">No recipe</span>
                                    @endif
                                </td>
                                <td style="text-align:right">{{ $recipe ? '₹'.Units::rupees($costs[$item->id]) : '—' }}</td>
                                <td class="col-action"><a href="{{ route('prepared.edit', $item->id) }}" class="btn btn-sm btn-ghost">{{ $recipe ? 'Recipe' : 'Write recipe' }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
