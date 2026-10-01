@extends('layouts.app')

@section('title', 'Send stock')

@section('page')
    <x-khana::page-header title="Send stock" subtitle="To another outlet of your business"
        :crumbs="['Transfers' => route('transfers.index'), 'New' => null]" />

    <form method="POST" action="{{ route('transfers.store') }}" class="card">
        @csrf

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="to_outlet_id">Send to</label>
                    <select class="form-control" id="to_outlet_id" name="to_outlet_id" required>
                        @if ($others->count() > 1) <option value="">Choose an outlet…</option> @endif
                        @foreach ($others as $other)
                            <option value="{{ $other->id }}" @selected(old('to_outlet_id') === $other->id)>{{ $other->name }}</option>
                        @endforeach
                    </select>
                    @error('to_outlet_id')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="from_store_id">Take it from</label>
                    <select class="form-control" id="from_store_id" name="from_store_id">
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('from_store_id', $from->id) === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
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
                <input class="form-control" id="note" name="note" value="{{ old('note') }}" maxlength="500" placeholder="For the weekend rush">
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:space-between;align-items:center">
            <span class="form-hint">The oldest batches go first. It leaves your stock when you send it, and joins theirs when they receive it.</span>
            <span style="display:flex;gap:8px">
                <a href="{{ route('transfers.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Send</button>
            </span>
        </div>
    </form>
@endsection
