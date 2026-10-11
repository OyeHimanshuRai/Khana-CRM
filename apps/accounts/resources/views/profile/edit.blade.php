@extends('khana::layouts.app')

@section('title', 'Your profile')

@section('content')
    <x-khana::page-header title="Your profile" subtitle="The one profile you carry into every product" />

    <form method="POST" action="{{ route('profile.update') }}" class="card" style="max-width:640px;margin-bottom:20px">
        @csrf @method('PUT')
        <div class="card-header"><span class="card-title">Details</span></div>
        <div class="card-body">
            <div class="field">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}">
                    @error('email')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="mobile">Mobile</label>
                    <input class="form-control" id="mobile" name="mobile" value="{{ old('mobile', $user->mobile) }}">
                    @error('mobile')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Save</button></div>
    </form>

    <form method="POST" action="{{ route('profile.password') }}" class="card" style="max-width:640px">
        @csrf @method('PUT')
        <div class="card-header"><span class="card-title">Password</span></div>
        <div class="card-body">
            <div class="field">
                <label class="form-label" for="current">Current password</label>
                <input class="form-control" id="current" name="current" type="password" autocomplete="current-password" required>
                @error('current')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="password">New password</label>
                    <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
                    @error('password')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="password_confirmation">Confirm</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="form-hint">Changing your password signs you out of CRM and ERP.</div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Change password</button></div>
    </form>
@endsection
