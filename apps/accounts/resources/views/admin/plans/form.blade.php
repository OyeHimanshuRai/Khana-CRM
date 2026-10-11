@extends('khana::layouts.app')

@php $editing = $plan->exists; @endphp

@section('title', $editing ? 'Edit plan' : 'New plan')

@section('content')
    <x-khana::page-header :title="$editing ? 'Edit plan' : 'New plan'"
        :crumbs="['Plans' => route('admin.plans.index'), ($editing ? $plan->name : 'New') => null]" />

    <form method="POST" action="{{ $editing ? route('admin.plans.update', $plan) : route('admin.plans.store') }}"
          class="card" style="max-width:720px">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $plan->name) }}" required>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $plan->code) }}" required>
                    @error('code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label class="form-label" for="blurb">Blurb <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="blurb" name="blurb" value="{{ old('blurb', $plan->blurb) }}">
            </div>

            <div class="field">
                <div class="form-label">Products</div>
                @foreach (['crm' => 'CRM', 'erp' => 'ERP'] as $product => $label)
                    <label class="check">
                        <input type="checkbox" name="products[]" value="{{ $product }}"
                               @checked(in_array($product, old('products', $plan->products ?? [])))>
                        {{ $label }}
                    </label>
                @endforeach
                @error('products')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="monthly_price">Monthly price (₹)</label>
                    <input class="form-control" id="monthly_price" name="monthly_price" type="number" min="0"
                           value="{{ old('monthly_price', $editing ? intdiv($plan->monthly_price_paise, 100) : '') }}" required>
                    @error('monthly_price')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="yearly_price">Yearly price (₹) <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="yearly_price" name="yearly_price" type="number" min="0"
                           value="{{ old('yearly_price', $editing && $plan->yearly_price_paise ? intdiv($plan->yearly_price_paise, 100) : '') }}">
                    @error('yearly_price')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="trial_days">Trial days</label>
                    <input class="form-control" id="trial_days" name="trial_days" type="number" min="0" max="365"
                           value="{{ old('trial_days', $plan->trial_days) }}" required>
                    @error('trial_days')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="sort_order">Sort order</label>
                    <input class="form-control" id="sort_order" name="sort_order" type="number"
                           value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
                </div>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active))>
                    Active (offered on sign-up)
                </label>
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:8px">
            <button class="btn btn-primary">{{ $editing ? 'Save' : 'Create plan' }}</button>
            <a href="{{ route('admin.plans.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
