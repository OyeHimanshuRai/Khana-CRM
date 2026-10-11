@extends('layouts.app')

@php
    use App\Http\Controllers\Stock\ItemController;
    use App\Support\Units;

    $editing = $item->exists;
    $big = ItemController::bigUnit(old('base_unit', $item->base_unit));

    // Packs as typed: a name and how much of the big unit one holds.
    $packs = old('packs', $item->packs->map(fn ($pack) => [
        'id' => $pack->id,
        'name' => $pack->name,
        'size' => Units::plain($item->base_unit === 'pc' ? $pack->qty : intdiv($pack->qty, 1000)),
    ])->all());
    $packs = array_pad(array_values($packs), max(count($packs) + 1, 2), ['id' => null, 'name' => '', 'size' => '']);
@endphp

@section('title', $editing ? 'Edit '.$item->name : 'Add stock item')

@section('page')
    <x-khana::page-header :title="$editing ? 'Edit stock item' : 'Add stock item'"
        :crumbs="['Items' => route('items.index'), ($editing ? $item->name : 'New') => null]" />

    <form method="POST" action="{{ $editing ? route('items.update', $item->id) : route('items.store') }}"
          class="card" style="max-width:860px">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $item->name) }}" required autofocus>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="category">Category <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="category" name="category" list="categories" value="{{ old('category', $item->category) }}" placeholder="Dairy, Vegetables, Dry goods…">
                    <datalist id="categories">
                        @foreach ($categories as $category) <option value="{{ $category }}"> @endforeach
                    </datalist>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="base_unit">Kept in</label>
                    <select class="form-control" id="base_unit" name="base_unit" @disabled($unitLocked)>
                        @foreach ($bases as $code => $label)
                            <option value="{{ $code }}" @selected(old('base_unit', $item->base_unit) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($unitLocked)
                        <input type="hidden" name="base_unit" value="{{ $item->base_unit }}">
                        <div class="form-hint">Cannot change: this item already has stock or a recipe.</div>
                    @else
                        <div class="form-hint">Weight, volume or count. Recipes and receipts may use kg, litres or your own packs.</div>
                    @endif
                    @error('base_unit')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="reorder">Tell me when below <span class="text-muted">({{ $big }})</span></label>
                    <input class="form-control" id="reorder" name="reorder" inputmode="decimal"
                           value="{{ old('reorder', $item->reorder_qty > 0 ? Units::plain($item->base_unit === 'pc' ? $item->reorder_qty : intdiv($item->reorder_qty, 1000)) : '') }}"
                           placeholder="Leave empty for no alert">
                    @error('reorder')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Bought as</div>
                <div class="form-hint" style="margin-bottom:8px">
                    Optional. A pack and how many {{ $big }} it holds - "5 kg bag" is 5, "Crate of 24" is 24.
                </div>

                @foreach ($packs as $i => $pack)
                    <div class="form-row">
                        <input type="hidden" name="packs[{{ $i }}][id]" value="{{ $pack['id'] ?? '' }}">
                        <div class="field">
                            <input class="form-control" name="packs[{{ $i }}][name]" value="{{ $pack['name'] ?? '' }}" placeholder="Pack name" aria-label="Pack name">
                        </div>
                        <div class="field">
                            <input class="form-control" name="packs[{{ $i }}][size]" value="{{ $pack['size'] ?? '' }}" inputmode="decimal" placeholder="Holds how many {{ $big }}" aria-label="Pack size">
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="form-section">
                <div class="form-section-title">Tax and codes <span class="text-muted">(optional)</span></div>
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="gst_rate">Input GST %</label>
                        <input class="form-control" id="gst_rate" name="gst_rate" inputmode="decimal" value="{{ old('gst_rate', $item->gst_rate_bp ? $item->gst_rate_bp / 100 : '') }}">
                        @error('gst_rate')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="hsn">HSN</label>
                        <input class="form-control" id="hsn" name="hsn" value="{{ old('hsn', $item->hsn) }}" maxlength="12">
                    </div>
                    <div class="field">
                        <label class="form-label" for="code">Your code</label>
                        <input class="form-control" id="code" name="code" value="{{ old('code', $item->code) }}" maxlength="64">
                        @error('code')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="barcode">Barcode</label>
                        <input class="form-control" id="barcode" name="barcode" value="{{ old('barcode', $item->barcode) }}" maxlength="64">
                        <div class="form-hint">A cola with the same barcode on the menu is linked by itself.</div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <input type="hidden" name="track_expiry" value="0">
                <label class="check">
                    <input type="checkbox" name="track_expiry" value="1" @checked(old('track_expiry', $item->track_expiry))>
                    Goes off - ask for a batch and an expiry date when it is received
                </label>

                <input type="hidden" name="is_prepared" value="0">
                <label class="check" style="margin-top:8px">
                    <input type="checkbox" name="is_prepared" value="1" @checked(old('is_prepared', $item->is_prepared))>
                    Made in-house from other items (a gravy base, a dough)
                </label>

                <input type="hidden" name="is_active" value="0">
                <label class="check" style="margin-top:8px">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))>
                    In use
                </label>
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ route('items.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add item' }}</button>
        </div>
    </form>
@endsection
