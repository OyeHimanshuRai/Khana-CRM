@extends('layouts.app')

@section('title', 'Receive goods')

@section('page')
    <x-khana::page-header title="Receive goods"
        :subtitle="$order ? 'Against order '.$order->number.' from '.$order->supplier->name : 'A delivery has arrived'"
        :crumbs="['Goods received' => route('receipts.index'), 'New' => null]" />

    <form method="POST" action="{{ route('receipts.store') }}" class="card">
        @csrf
        @if ($order) <input type="hidden" name="order_id" value="{{ $order->id }}"> @endif

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="supplier_id">Supplier <span class="text-muted">(optional)</span></label>
                    <select class="form-control" id="supplier_id" name="supplier_id">
                        <option value="">No supplier - cash purchase</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $order?->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-hint">With a supplier, the bill for this delivery is raised for you.</div>
                </div>
                <div class="field">
                    <label class="form-label" for="bill_no">Their bill number <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="bill_no" name="bill_no" value="{{ old('bill_no') }}" maxlength="60">
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="received_on">Received on</label>
                    <input class="form-control" type="date" id="received_on" name="received_on" value="{{ old('received_on', $today) }}" required>
                    @error('received_on')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="store_id">Into</label>
                    <select class="form-control" id="store_id" name="store_id" required>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('store_id', $mainStore->id) === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="form-label" for="due_on">Pay by <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="date" id="due_on" name="due_on" value="{{ old('due_on') }}">
                    <div class="form-hint">Left empty, the supplier's usual terms apply.</div>
                </div>
            </div>
        </div>

        @include('partials.lines', ['catalogue' => $catalogue, 'rows' => $rows, 'columns' => ['price', 'batch']])

        <div class="card-body">
            <div class="form-hint">
                Price is what one unit cost before tax - per kg if you typed kg, per bag if you typed bags.
                Batch and expiry are asked only for items that go off.
            </div>

            <div class="field" style="margin-top:12px">
                <label class="form-label" for="notes">Note <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="notes" name="notes" value="{{ old('notes') }}" maxlength="500">
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ route('receipts.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Receive into stock</button>
        </div>
    </form>
@endsection
