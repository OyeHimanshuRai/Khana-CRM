@extends('layouts.app')

@section('title', 'Recipes')

@php
    use App\Services\Availability;
    use App\Support\Units;
@endphp

@section('page')
    <x-khana::page-header title="Recipes" subtitle="What each dish is made of, what it costs, and whether stock can still cover it" />

    {{-- What needs doing first. --}}
    @if ($needs->isNotEmpty())
        <div class="card" style="margin-bottom:18px">
            <div class="card-header">
                <h2 class="card-title">Needs a recipe <span class="badge badge-warning">{{ $needs->count() }}</span></h2>
            </div>
            <div class="card-body text-sm text-muted" style="padding-bottom:0">
                Until a dish has a recipe, selling it uses no stock and it has no cost.
            </div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($needs->take(30) as $need)
                            <tr>
                                <td>
                                    <strong>{{ $need['item']->name }}</strong>
                                    @if ($need['size']) <span class="text-muted">· {{ $need['size']->name }}</span> @endif
                                    <div class="text-xs text-muted">{{ $need['item']->category }} · from {{ $need['item']->sourceLabel() }}</div>
                                </td>
                                <td class="col-action">
                                    @can('recipes.manage')
                                        <a href="{{ route('recipes.edit', array_filter(['item' => $need['item']->id, 'size' => $need['size']?->id])) }}" class="btn btn-sm btn-primary">Write recipe</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($needs->count() > 30)
                <div class="card-footer text-sm text-muted">And {{ $needs->count() - 30 }} more - they appear here as these are done.</div>
            @endif
        </div>
    @endif

    <div class="card" style="margin-bottom:18px">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-khana::icon name="search" :size="15" />
                <label for="q" class="sr-only">Search dishes</label>
                <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Search dishes…">
            </div>
        </form>

        @if ($dishes->isEmpty())
            <x-khana::empty icon="file" title="No dishes yet"
                text="Dishes arrive here from Khana CRM's menu by themselves. With other billing software, import a sales file and each item in it appears here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Dish</th><th>Size</th><th style="text-align:right">Price</th><th style="text-align:right">Cost</th><th style="text-align:right">Food cost</th><th>Stock covers</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($dishes as $dish)
                            @foreach ($snapshots[$dish->id] as $row)
                                <tr>
                                    <td>
                                        @if ($loop->first)
                                            <strong>{{ $dish->name }}</strong>
                                            <div class="text-xs text-muted">{{ $dish->category }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $row['size_name'] ?? '—' }}</td>
                                    <td style="text-align:right">{{ $row['price_paise'] > 0 ? '₹'.Units::rupees($row['price_paise']) : '—' }}</td>
                                    <td style="text-align:right">{{ $row['has_recipe'] ? '₹'.Units::rupees($row['cost_paise']) : '—' }}</td>
                                    <td style="text-align:right">
                                        @php $percent = $row['has_recipe'] ? Availability::percent($row['cost_paise'], $row['price_paise']) : null; @endphp
                                        {{ $percent !== null ? $percent.'%' : '—' }}
                                    </td>
                                    <td>
                                        @if (! $row['has_recipe'])
                                            <span class="badge badge-warning">No recipe</span>
                                        @elseif ($row['portions'] === null)
                                            <span class="text-muted">—</span>
                                        @elseif ($row['available'])
                                            <span class="badge badge-success">{{ intdiv($row['portions'], Units::SCALE) }} portions</span>
                                        @else
                                            <span class="badge badge-danger">Sold out</span>
                                        @endif
                                    </td>
                                    <td class="col-action">
                                        <a href="{{ route('recipes.edit', array_filter(['item' => $dish->id, 'size' => $row['size_id'] ?: null])) }}" class="btn btn-sm btn-ghost">
                                            {{ $row['has_recipe'] ? 'Recipe' : 'Write' }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $dishes])
        @endif
    </div>

    @if ($addons->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="card-title">Add-ons</h2></div>
            <div class="card-body text-sm text-muted" style="padding-bottom:0">
                An add-on's recipe is used on top of the dish it is ordered with: extra cheese adds 20 g of cheese.
            </div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($addons as $addon)
                            <tr>
                                <td>
                                    {{ $addon->name }}
                                    <span class="text-xs text-muted">· {{ $addon->group_name }}</span>
                                </td>
                                <td>
                                    @if (isset($addonRecipes[$addon->id]))
                                        <span class="badge badge-success">Has a recipe</span>
                                    @else
                                        <span class="text-muted text-sm">Uses nothing</span>
                                    @endif
                                </td>
                                <td class="col-action"><a href="{{ route('recipes.addon', $addon->id) }}" class="btn btn-sm btn-ghost">Recipe</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
