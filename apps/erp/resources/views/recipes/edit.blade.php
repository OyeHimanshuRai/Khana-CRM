@extends('layouts.app')

@php
    use App\Services\Availability;
    use App\Support\Units;

    $heading = $dish->name.($size ? ' · '.$size->name : '');
    $params = array_filter(['item' => $dish->id, 'size' => $size?->id]);
    $percent = $snapshot && $snapshot['has_recipe'] ? Availability::percent($snapshot['cost_paise'], $snapshot['price_paise']) : null;
@endphp

@section('title', 'Recipe: '.$heading)

@section('page')
    <x-khana::page-header :title="$heading"
        :subtitle="$size ? 'The recipe for this size' : ($dish->sizes->isNotEmpty() ? 'The recipe every size shares, unless a size has one of its own' : 'What one portion is made of')"
        :crumbs="['Recipes' => route('recipes.index'), $heading => null]" />

    @if ($dish->sizes->isNotEmpty())
        <nav class="hub-tabs" aria-label="Sizes" style="margin-bottom:18px">
            <div class="tabs">
                <a class="tab {{ $size === null ? 'is-active' : '' }}" style="text-decoration:none;padding:10px 16px" href="{{ route('recipes.edit', ['item' => $dish->id]) }}">All sizes</a>
                @foreach ($dish->sizes as $option)
                    <a class="tab {{ $size?->id === $option->id ? 'is-active' : '' }}" style="text-decoration:none;padding:10px 16px"
                       href="{{ route('recipes.edit', ['item' => $dish->id, 'size' => $option->id]) }}">{{ $option->name }}</a>
                @endforeach
            </div>
        </nav>
    @endif

    @if ($snapshot && $snapshot['has_recipe'])
        <div class="stat-grid" style="margin-bottom:18px">
            <div class="stat">
                <div class="stat-body">
                    <div class="stat-label">A portion costs</div>
                    <div class="stat-value">&#8377;{{ Units::rupees($snapshot['cost_paise']) }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-body">
                    <div class="stat-label">Food cost</div>
                    <div class="stat-value">{{ $percent !== null ? $percent.'%' : '—' }}</div>
                    <div class="text-xs text-muted">of &#8377;{{ Units::rupees($snapshot['price_paise']) }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-body">
                    <div class="stat-label">Stock covers</div>
                    <div class="stat-value">{{ $snapshot['portions'] === null ? '—' : intdiv($snapshot['portions'], Units::SCALE).' portions' }}</div>
                </div>
            </div>
        </div>
    @endif

    @if ($shared)
        <div class="alert alert-info" role="status" style="margin-bottom:18px">
            {{ $size->name }} has no recipe of its own, so it is cooked by the one every size shares
            ({{ $shared->lines->map(fn ($line) => $line->item?->name)->filter()->implode(', ') }}).
            Write one below to replace it for this size only.
        </div>
    @endif

    <form method="POST" action="{{ route('recipes.update', $params) }}" class="card" style="margin-bottom:18px">
        @csrf @method('PUT')

        <div class="card-header"><h2 class="card-title">One portion uses</h2></div>

        @include('partials.lines', ['catalogue' => $catalogue, 'rows' => $rows, 'columns' => ['optional'], 'blank' => 5])

        <div class="card-body">
            <div class="form-hint">
                Tick "Optional" for a garnish: it is used, but running out of it does not mark the dish sold out.
            </div>

            <div class="field" style="margin-top:12px">
                <label class="form-label" for="notes">Note for the kitchen <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="notes" name="notes" value="{{ old('notes', $recipe?->notes) }}" maxlength="500">
            </div>
        </div>

        @can('recipes.manage')
            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a href="{{ route('recipes.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save recipe</button>
            </div>
        @endcan
    </form>

    @if ($recipe)
        @can('recipes.manage')
            <form method="POST" action="{{ route('recipes.destroy', $params) }}" onsubmit="return confirm('Remove this recipe? Selling the dish will stop using stock.')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost">Remove this recipe</button>
            </form>
        @endcan
    @endif
@endsection
