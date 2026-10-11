@extends('layouts.app')

@section('title', 'Recipe: '.$item->name)

@section('page')
    <x-khana::page-header :title="$item->name" subtitle="What one batch takes, and how much it makes"
        :crumbs="['Prepared items' => route('prepared.index'), $item->name => null]" />

    <form method="POST" action="{{ route('prepared.update', $item->id) }}" class="card">
        @csrf @method('PUT')

        <div class="card-body">
            <div class="form-row">
                <div class="field" style="max-width:260px">
                    <label class="form-label" for="yield">One batch makes ({{ $big }})</label>
                    <input class="form-control" id="yield" name="yield" inputmode="decimal" value="{{ old('yield', $yield) }}" required>
                    @error('yield')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card-header"><h2 class="card-title">One batch uses</h2></div>

        @include('partials.lines', ['catalogue' => $catalogue, 'rows' => $rows, 'blank' => 5])

        <div class="card-body">
            <div class="field">
                <label class="form-label" for="notes">Method or note <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="notes" name="notes" value="{{ old('notes', $recipe?->notes) }}" maxlength="500">
            </div>
        </div>

        @can('recipes.manage')
            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a href="{{ route('prepared.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save recipe</button>
            </div>
        @endcan
    </form>
@endsection
