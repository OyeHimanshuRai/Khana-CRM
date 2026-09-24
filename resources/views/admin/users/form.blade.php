@extends('admin.layouts.app')

@php
    $isNew = ! $user->exists;
    $isSelf = ! $isNew && $user->is(auth()->user());
@endphp

@section('title', $isNew ? 'New User' : 'Edit '.$user->name)

@section('content')
    <x-page-header
        :title="$isNew ? 'New User' : $user->name"
        :subtitle="$isNew ? 'Create an account and assign its roles.' : $user->email"
        :crumbs="['Settings' => null, 'Users' => route('admin.users.index'), ($isNew ? 'New' : 'Edit') => null]"
    >
        <x-slot:actions>
            @unless ($isNew)
                @allows('settings.users.edit')
                    {{-- A fresh link, for when the first email bounced, went
                         to spam, or expired before they got to it. --}}
                    {{-- Cancelling has to stop the event as well as prevent it:
                         app.js listens on document, so a plain `return false`
                         suppresses the normal submit and posts it anyway. --}}
                    <form method="POST" action="{{ route('admin.users.welcome', $user) }}" data-ajax
                          onsubmit="if (! confirm('Send a welcome email to {{ $user->email }}? It carries a new set-password link, which replaces any earlier one.')) { event.stopPropagation(); return false; }">
                        @csrf
                        <button type="submit" class="btn btn-sm">
                            <x-icon name="mail" :size="14" /> Resend welcome email
                        </button>
                    </form>
                @endallows
            @endunless

            <a class="btn btn-sm" href="{{ route('admin.users.index') }}">Back to users</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" data-ajax
          action="{{ $isNew ? route('admin.users.store') : route('admin.users.update', $user) }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="card">
            <div class="card-header">
                <div class="card-title">Account</div>
            </div>

            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label for="name">Full name</label>
                        <input id="name" type="text" name="name" required
                               value="{{ old('name', $user->name) }}"
                               aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                        @error('name')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="email">Email address</label>
                        <input id="email" type="email" name="email" required
                               value="{{ old('email', $user->email) }}"
                               aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                        @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="password">{{ $isNew ? 'Password' : 'New password' }}</label>
                        <input id="password" type="password" name="password" autocomplete="new-password"
                               @required($isNew)
                               aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                        <div class="form-hint">
                            {{ $isNew ? 'Minimum 8 characters.' : 'Leave blank to keep the current password.' }}
                        </div>
                        @error('password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation"
                               autocomplete="new-password" @required($isNew)>
                    </div>
                </div>

                <div class="field" style="margin-bottom:{{ $isNew ? '16px' : '0' }}">
                    <label class="check">
                        <input type="hidden" name="is_admin" value="0">
                        <input type="checkbox" name="is_admin" value="1"
                               @checked(old('is_admin', $user->is_admin ?? false))>
                        Allow sign-in to the admin panel
                    </label>
                    <div class="form-hint">
                        Without this the account cannot reach /admin at all, whatever its roles.
                    </div>
                </div>

                @if ($isNew)
                    <div class="field" style="margin-bottom:0">
                        <label class="check">
                            {{-- The hidden field is what makes an unticked box
                                 submit a 0 rather than nothing at all. --}}
                            <input type="hidden" name="send_welcome" value="0">
                            <input type="checkbox" name="send_welcome" value="1"
                                   @checked(old('send_welcome', true))>
                            Email a welcome message to this address
                        </label>
                        <div class="form-hint">
                            Includes a one-time link for them to choose their own password.
                            The password typed above is never emailed.
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Roles</div>
                    <div class="text-xs text-muted">Permissions from every assigned role are combined.</div>
                </div>
            </div>

            <div class="card-body">
                <div style="display:flex;flex-wrap:wrap;gap:9px">
                    @foreach ($roles as $role)
                        @php
                            $isSuper = $role->name === \App\Models\User::SUPER_ADMIN;
                            // Blocking self-removal here mirrors the server-side guard.
                            $lockSelf = $isSelf && $isSuper && in_array($role->name, $assignedRoles, true);
                        @endphp

                        <label class="pmchip {{ $isSuper ? 'tone-warning' : 'tone-info' }}"
                               @if ($lockSelf) title="You cannot remove your own Super Admin role" @endif>
                            <input type="checkbox" name="roles[]" value="{{ $role->name }}"
                                   @checked(in_array($role->name, old('roles', $assignedRoles), true))
                                   @disabled($lockSelf)>
                            <span>{{ $role->name }}</span>
                        </label>

                        @if ($lockSelf)
                            <input type="hidden" name="roles[]" value="{{ $role->name }}">
                        @endif
                    @endforeach
                </div>

                @error('roles')<span class="field-error" role="alert">{{ $message }}</span>@enderror
            </div>

            <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
                <a class="btn" href="{{ route('admin.users.index') }}">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    {{ $isNew ? 'Create user' : 'Save changes' }}
                </button>
            </div>
        </div>
    </form>

    @unless ($isNew)
        {{-- Direct grants sit on top of the roles above. Chips marked "R" are
             already covered by a role, so ticking them changes nothing. --}}
        <form method="POST" action="{{ route('admin.users.permissions', $user) }}" data-ajax>
            @csrf

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">User-specific permissions</div>
                        <div class="text-xs text-muted">
                            Extra access for this person only, added on top of their roles.
                        </div>
                    </div>
                    <span class="badge badge-info">{{ count($inherited) }} inherited from roles</span>
                </div>

                <div class="card-body">
                    <x-permission-matrix
                        :tree="$tree"
                        :granted="$directPermissions"
                        :inherited="$inherited"
                    />
                </div>

                <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary">Save direct permissions</button>
                </div>
            </div>
        </form>
    @endunless
@endsection
