@extends('layouts.app')

@section('title', 'Issue stock')

@section('page')
    <x-khana::page-header title="Issue to kitchen" subtitle="Move stock from one store to another"
        :crumbs="['Issues' => route('issues.index'), 'New' => null]" />

    <form method="POST" action="{{ route('issues.store') }}" class="card">
        @csrf

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="from_store_id">From</label>
                    <select class="form-control" id="from_store_id" name="from_store_id">
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('from_store_id', $from->id) === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="form-label" for="to_store_id">To</label>
                    <select class="form-control" id="to_store_id" name="to_store_id">
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('to_store_id', $to->id) === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                    @error('to_store_id')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="on_date">On</label>
                    <input class="form-control" type="date" id="on_date" name="on_date" value="{{ old('on_date', $today) }}" required>
                </div>
            </div>
        </div>

        @include('partials.lines', ['catalogue' => $catalogue])

        <div class="card-body">
            <div class="field">
                <label class="form-label" for="note">Note <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="note" name="note" value="{{ old('note') }}" maxlength="500" placeholder="Evening service">
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ route('issues.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Move stock</button>
        </div>
    </form>
@endsection
