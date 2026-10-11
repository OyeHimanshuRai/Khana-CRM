@extends('khana::layouts.guest')

@section('title', 'Start a trial')
@section('card-class', 'auth-card-wide')

@section('content')
    <div class="auth-head">
        <x-khana::brand sub="Accounts" />
        <h1 class="auth-title">Start your free trial</h1>
        <p class="text-sm text-muted">Your business, your first outlet, and you - set up in one go.</p>
    </div>

    <form method="POST" action="{{ route('signup') }}" class="auth-form">
        @csrf

        <div class="form-section-title">Your business</div>
        <div class="field">
            <label class="form-label" for="business_name">Business name</label>
            <input class="form-control" id="business_name" name="business_name" value="{{ old('business_name') }}" required>
            @error('business_name')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-section-title">First outlet</div>
        <div class="form-row">
            <div class="field">
                <label class="form-label" for="outlet_name">Outlet name</label>
                <input class="form-control" id="outlet_name" name="outlet_name" value="{{ old('outlet_name') }}" required>
                @error('outlet_name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="form-label" for="outlet_code">Short code</label>
                <input class="form-control" id="outlet_code" name="outlet_code" value="{{ old('outlet_code') }}"
                       placeholder="GF" maxlength="12" required>
                <div class="form-hint">Appears in bill numbers, e.g. GF.</div>
                @error('outlet_code')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-row">
            <div class="field">
                <label class="form-label" for="city">City</label>
                <input class="form-control" id="city" name="city" value="{{ old('city') }}" required>
                @error('city')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="form-label" for="state_code">State</label>
                <select class="form-control" id="state_code" name="state_code" required>
                    <option value="">Choose…</option>
                    @foreach ($states as $code => $name)
                        <option value="{{ $code }}" @selected(old('state_code') === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('state_code')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="form-section-title">You (the owner)</div>
        <div class="field">
            <label class="form-label" for="owner_name">Your name</label>
            <input class="form-control" id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required>
            @error('owner_name')<div class="field-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-row">
            <div class="field">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required>
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="form-label" for="mobile">Mobile <span class="text-muted">(optional)</span></label>
                <input class="form-control" id="mobile" name="mobile" value="{{ old('mobile') }}">
                @error('mobile')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-row">
            <div class="field">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="form-label" for="password_confirmation">Confirm password</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>
        </div>

        <div class="form-section-title">Your plan</div>
        <div class="field">
            <label class="form-label" for="plan">Plan</label>
            <select class="form-control" id="plan" name="plan" required>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->code }}" @selected(old('plan') === $plan->code)>
                        {{ $plan->name }} — {{ implode(' + ', $plan->productLabels()) }}
                        ({{ $plan->trial_days }}-day trial)
                    </option>
                @endforeach
            </select>
            @error('plan')<div class="field-error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label class="form-label" for="period">Billing</label>
            <select class="form-control" id="period" name="period" required>
                <option value="monthly" @selected(old('period', 'monthly') === 'monthly')>Monthly</option>
                <option value="yearly" @selected(old('period') === 'yearly')>Yearly</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Start free trial</button>
    </form>

    <p class="text-sm text-muted auth-foot">
        Already have an account? <a href="{{ route('login') }}">Sign in</a>.
    </p>
@endsection
