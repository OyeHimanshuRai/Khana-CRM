@extends('layouts.app')

@section('title', 'New order')

@section('page')
    <x-khana::page-header title="New purchase order" :crumbs="['Orders' => route('orders.index'), 'New' => null]" />

    @if ($suppliers->isEmpty())
        <div class="card">
            <x-khana::empty icon="users" title="Add a supplier first" text="An order is to somebody.">
                <a href="{{ route('suppliers.index') }}" class="btn btn-primary">Add a supplier</a>
            </x-khana::empty>
        </div>
    @else
        <form method="POST" action="{{ route('orders.store') }}" class="card">
            @csrf

            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="supplier_id">Supplier</label>
                        <select class="form-control" id="supplier_id" name="supplier_id" required>
                            <option value="">Choose…</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) old('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                        @error('supplier_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="ordered_on">Ordered on</label>
                        <input class="form-control" type="date" id="ordered_on" name="ordered_on" value="{{ old('ordered_on', $today) }}" required>
                    </div>
                    <div class="field">
                        <label class="form-label" for="expected_on">Expected <span class="text-muted">(optional)</span></label>
                        <input class="form-control" type="date" id="expected_on" name="expected_on" value="{{ old('expected_on') }}">
                        @error('expected_on')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            @include('partials.lines', ['catalogue' => $catalogue, 'columns' => ['price']])

            <div class="card-body">
                <div class="field">
                    <label class="form-label" for="notes">Note to the supplier <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="notes" name="notes" value="{{ old('notes') }}" maxlength="500">
                </div>
            </div>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
                <a href="{{ route('orders.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save order</button>
            </div>
        </form>
    @endif
@endsection
