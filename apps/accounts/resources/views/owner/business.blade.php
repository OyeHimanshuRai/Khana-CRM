@extends('khana::layouts.app')

@section('title', 'Business')

@section('content')
    <x-khana::page-header title="Business" subtitle="The details on your bills and returns" />

    <form method="POST" action="{{ route('business.update') }}" class="card" style="max-width:720px">
        @csrf
        @method('PUT')

        <div class="card-body">
            <div class="field">
                <label class="form-label" for="name">Business name</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $tenant->name) }}" required>
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label class="form-label" for="legal_name">Legal name <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="legal_name" name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}">
                @error('legal_name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="gstin">GSTIN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="gstin" name="gstin" value="{{ old('gstin', $tenant->gstin) }}" maxlength="15">
                    @error('gstin')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="pan">PAN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="pan" name="pan" value="{{ old('pan', $tenant->pan) }}" maxlength="10">
                    @error('pan')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="email">Email <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $tenant->email) }}">
                    @error('email')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="phone">Phone <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="phone" name="phone" value="{{ old('phone', $tenant->phone) }}">
                    @error('phone')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
@endsection
