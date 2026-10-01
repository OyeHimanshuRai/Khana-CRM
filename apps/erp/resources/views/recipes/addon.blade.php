@extends('layouts.app')

@section('title', 'Recipe: '.$addon->name)

@section('page')
    <x-khana::page-header :title="$addon->name"
        :subtitle="'Add-on · '.$addon->group_name.' - used on top of the dish it is ordered with'"
        :crumbs="['Recipes' => route('recipes.index'), $addon->name => null]" />

    <form method="POST" action="{{ route('recipes.addon.update', $addon->id) }}" class="card">
        @csrf @method('PUT')

        <div class="card-header"><h2 class="card-title">One of these adds</h2></div>

        @include('partials.lines', ['catalogue' => $catalogue, 'rows' => $rows, 'blank' => 3])

        <div class="card-body">
            <div class="form-hint">Leave every row blank if this add-on uses nothing from the store.</div>
        </div>

        @can('recipes.manage')
            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a href="{{ route('recipes.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        @endcan
    </form>
@endsection
