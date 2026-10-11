@extends('layouts.app')

@section('title', 'Make a batch')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Make a batch" subtitle="Something prepared in-house, from its recipe"
        :crumbs="['Production' => route('production.index'), 'New' => null]" />

    @if ($items->isEmpty())
        <div class="card">
            <x-khana::empty icon="package" title="Nothing is made in-house yet"
                text="Tick &quot;Made in-house&quot; on a stock item - a gravy base, a dough - then write its recipe.">
                @can('stock.manage')
                    <a href="{{ route('items.create') }}" class="btn btn-primary">Add a stock item</a>
                @endcan
            </x-khana::empty>
        </div>
    @else
        <form method="POST" action="{{ route('production.store') }}" class="card" style="max-width:820px">
            @csrf

            <div class="card-body">
                <div class="field">
                    <label class="form-label" for="stock_item_id">What was made</label>
                    <select class="form-control" id="stock_item_id" name="stock_item_id" required>
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" @selected((int) old('stock_item_id') === $item->id) @disabled(! isset($withRecipe[$item->id]))>
                                {{ $item->name }}
                                @if (isset($withRecipe[$item->id]))
                                    (recipe makes {{ $item->show($withRecipe[$item->id]) }})
                                @else
                                    - no recipe yet
                                @endif
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        The ingredients leave stock in proportion to how much you made.
                        <a href="{{ route('prepared.index') }}">Write or change a recipe</a>.
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="qty">How much</label>
                        <input class="form-control" id="qty" name="qty" inputmode="decimal" value="{{ old('qty') }}" required>
                        @error('qty')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="unit">Unit</label>
                        <select class="form-control" id="unit" name="unit">
                            @foreach (['kg' => 'kg', 'l' => 'litre', 'pc' => 'pc', 'g' => 'g', 'ml' => 'ml'] as $code => $label)
                                <option value="{{ $code }}" @selected(old('unit') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="store_id">In</label>
                        <select class="form-control" id="store_id" name="store_id">
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" @selected((int) old('store_id', $kitchen->id) === $store->id)>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="on_date">On</label>
                        <input class="form-control" type="date" id="on_date" name="on_date" value="{{ old('on_date', $today) }}" required>
                    </div>
                </div>

                <div class="field">
                    <label class="form-label" for="note">Note <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="note" name="note" value="{{ old('note') }}" maxlength="500">
                </div>
            </div>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a href="{{ route('production.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Record batch</button>
            </div>
        </form>
    @endif
@endsection
