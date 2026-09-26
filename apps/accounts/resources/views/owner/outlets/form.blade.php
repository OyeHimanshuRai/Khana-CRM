@extends('khana::layouts.app')

@php $editing = $outlet->exists; @endphp

@section('title', $editing ? 'Edit outlet' : 'Add outlet')

@section('content')
    <x-khana::page-header :title="$editing ? 'Edit outlet' : 'Add outlet'"
        :crumbs="['Outlets' => route('outlets.index'), ($editing ? $outlet->name : 'New') => null]" />

    <form method="POST" action="{{ $editing ? route('outlets.update', $outlet) : route('outlets.store') }}"
          class="card" style="max-width:820px">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="name">Outlet name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $outlet->name) }}" required>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="code">Short code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $outlet->code) }}" maxlength="12" required>
                    @error('code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label class="form-label" for="address_line1">Address</label>
                <input class="form-control" id="address_line1" name="address_line1" value="{{ old('address_line1', $outlet->address_line1) }}" required>
                @error('address_line1')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <input class="form-control" name="address_line2" value="{{ old('address_line2', $outlet->address_line2) }}" placeholder="Line 2 (optional)">
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="city">City</label>
                    <input class="form-control" id="city" name="city" value="{{ old('city', $outlet->city) }}" required>
                    @error('city')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="pincode">PIN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="pincode" name="pincode" value="{{ old('pincode', $outlet->pincode) }}" maxlength="6">
                    @error('pincode')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="state_code">State</label>
                    <select class="form-control" id="state_code" name="state_code" required>
                        <option value="">Choose…</option>
                        @foreach ($states as $code => $name)
                            <option value="{{ $code }}" @selected(old('state_code', $outlet->state_code) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('state_code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="gstin">GSTIN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="gstin" name="gstin" value="{{ old('gstin', $outlet->gstin) }}" maxlength="15">
                    @error('gstin')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="fssai">FSSAI <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="fssai" name="fssai" value="{{ old('fssai', $outlet->fssai) }}" maxlength="14">
                    @error('fssai')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="phone">Phone <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="phone" name="phone" value="{{ old('phone', $outlet->phone) }}">
                    @error('phone')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            @unless ($editing)
                <div class="form-section-title">Plan for this outlet</div>
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="plan">Plan</label>
                        <select class="form-control" id="plan" name="plan" required>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->code }}" @selected(old('plan') === $plan->code)>
                                    {{ $plan->name }} — {{ implode(' + ', $plan->productLabels()) }} ({{ $plan->trial_days }}-day trial)
                                </option>
                            @endforeach
                        </select>
                        @error('plan')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="period">Billing</label>
                        <select class="form-control" id="period" name="period" required>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                </div>
            @endunless
        </div>

        <div class="card-footer" style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add outlet' }}</button>
            <a href="{{ route('outlets.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
