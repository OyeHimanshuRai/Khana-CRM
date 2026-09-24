@extends('admin.layouts.app')

@php $isNew = ! $role->exists; @endphp

@section('title', $isNew ? 'New Role' : 'Edit '.$role->name)

@section('content')
    <x-page-header
        :title="$isNew ? 'New Role' : $role->name"
        :subtitle="$isNew
            ? 'Name the role, then choose what it can do.'
            : ($locked
                ? 'This role has unrestricted access and cannot be edited.'
                : 'Changes to permissions apply to every user holding this role.')"
        :crumbs="['Settings' => null, 'Roles' => route('admin.roles.index'), ($isNew ? 'New' : 'Edit') => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.roles.index') }}">Back to roles</a>
        </x-slot:actions>
    </x-page-header>

    @if ($isNew)
        {{-- Creation posts name + matrix together, then follows the redirect
             the response carries to the new role's own edit screen. --}}
        <form method="POST" action="{{ route('admin.roles.store') }}" data-ajax>
            @csrf

            <div class="card">
                <div class="card-body">
                    <div class="field" style="max-width:420px;margin-bottom:0">
                        <label for="name">Role name</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required
                               placeholder="e.g. Warehouse Supervisor"
                               aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                        @error('name')
                            <span class="field-error" role="alert">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">Permissions</div>
                </div>
                <div class="card-body">
                    <x-permission-matrix :tree="$tree" :granted="$granted" />
                </div>
                <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
                    <a class="btn" href="{{ route('admin.roles.index') }}">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create role</button>
                </div>
            </div>
        </form>
    @else
        <div class="card">
            <div class="card-header">
                <div class="card-title">Details</div>
                @if ($locked)
                    <span class="badge badge-brand">Protected role</span>
                @endif
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.roles.update', $role) }}" data-ajax
                      style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    @method('PUT')

                    <div class="field" style="flex:1 1 300px;max-width:420px;margin-bottom:0">
                        <label for="name">Role name</label>
                        <input id="name" type="text" name="name" value="{{ old('name', $role->name) }}"
                               required @disabled($locked)
                               aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                        @error('name')
                            <span class="field-error" role="alert">{{ $message }}</span>
                        @enderror
                    </div>

                    @unless ($locked)
                        <button type="submit" class="btn">Rename</button>
                    @endunless

                    <div class="text-xs text-muted" style="margin-left:auto">
                        {{ $role->users()->count() }} user(s) hold this role
                    </div>
                </form>
            </div>
        </div>

        {{-- Permissions save over AJAX; app.js turns the JSON envelope into
             a toast, so the matrix state is never lost to a page reload. --}}
        <form method="POST" action="{{ route('admin.roles.permissions', $role) }}" data-ajax>
            @csrf

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Permissions</div>
                        <div class="text-xs text-muted">Module → sub-module → action</div>
                    </div>
                </div>

                <div class="card-body">
                    <x-permission-matrix :tree="$tree" :granted="$granted" :locked="$locked" />
                </div>

                @unless ($locked)
                    <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end;align-items:center">
                        <span class="text-xs text-muted" style="margin-right:auto">
                            Saved without leaving the page.
                        </span>
                        <button type="submit" class="btn btn-primary">Save permissions</button>
                    </div>
                @endunless
            </div>
        </form>
    @endif
@endsection
