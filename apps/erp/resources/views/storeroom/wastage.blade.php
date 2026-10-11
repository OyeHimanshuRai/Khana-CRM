@extends('layouts.app')

@section('title', 'Log wastage')

@section('page')
    <x-khana::page-header title="Log wastage" subtitle="Stock that was spoiled, spilt or thrown away"
        :crumbs="['Wastage' => route('wastage.index'), 'New' => null]" />

    <form method="POST" action="{{ route('wastage.store') }}" class="card">
        @csrf

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="reason">Why</label>
                    <input class="form-control" id="reason" name="reason" list="reasons" value="{{ old('reason') }}" required autofocus>
                    <datalist id="reasons">
                        @foreach ($reasons as $reason) <option value="{{ $reason }}"> @endforeach
                    </datalist>
                    @error('reason')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="store_id">From</label>
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
        </div>

        @include('partials.lines', ['catalogue' => $catalogue, 'columns' => ['note']])

        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ route('wastage.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Write off</button>
        </div>
    </form>
@endsection
