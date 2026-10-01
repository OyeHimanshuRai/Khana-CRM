@extends('layouts.app')

@section('title', 'Stores')

@section('page')
    <x-khana::page-header title="Stores" subtitle="The places stock sits at this outlet" />

    <div class="card" style="margin-bottom:18px">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Store</th><th>Code</th><th>Used for</th><th></th></tr></thead>
                <tbody>
                    @foreach ($stores as $store)
                        <tr>
                            <td colspan="4" style="padding:0">
                                <form method="POST" action="{{ route('stores.update', $store->id) }}"
                                      style="display:grid;grid-template-columns:2fr 1fr 2fr auto;gap:12px;align-items:center;padding:10px 16px">
                                    @csrf @method('PUT')
                                    <input class="form-control" name="name" value="{{ $store->name }}" required aria-label="Store name">
                                    <span class="text-muted">{{ $store->code }}</span>
                                    <span class="text-sm">
                                        @if ($store->kind === 'main')
                                            Goods are received here
                                        @elseif ($store->kind === 'kitchen')
                                            Recipes are cooked from here
                                        @else
                                            <input type="hidden" name="is_active" value="0">
                                            <label class="check"><input type="checkbox" name="is_active" value="1" @checked($store->is_active)> In use</label>
                                        @endif
                                    </span>
                                    <button type="submit" class="btn btn-sm">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('stores.store') }}" class="card" style="max-width:620px">
        @csrf
        <div class="card-header"><h2 class="card-title">Add a store</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" placeholder="Bar, Cold room…" required>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="code">Short code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code') }}" maxlength="12" placeholder="BAR" required>
                    @error('code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Add store</button>
        </div>
    </form>
@endsection
