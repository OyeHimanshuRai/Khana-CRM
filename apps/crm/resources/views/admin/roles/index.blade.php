@extends('admin.layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
    <x-page-header
        title="Roles & Permissions"
        subtitle="{{ $roles->count() }} role(s) across {{ $totalPermissions }} declared permissions."
        :crumbs="['Settings' => null, 'Roles' => null]"
    >
        <x-slot:actions>
            @allows('settings.roles.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.roles.create') }}">
                    <x-icon name="plus" :size="15" /> New Role
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <form method="GET" class="pmatrix-search" style="max-width:320px">
                <x-icon name="search" :size="15" />
                <label for="role-search" class="sr-only">Search roles</label>
                <input id="role-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search roles…" autocomplete="off">
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Permissions</th>
                        <th>Users</th>
                        <th style="width:1%"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roles as $role)
                        @php $isSuper = $role->name === \App\Models\User::SUPER_ADMIN; @endphp
                        <tr>
                            <td>
                                <strong style="color:var(--heading)">{{ $role->name }}</strong>
                                @if ($isSuper)
                                    <span class="badge badge-brand" style="margin-left:6px">Protected</span>
                                @endif
                            </td>
                            <td>
                                @if ($isSuper)
                                    <span class="badge badge-success">All ({{ $totalPermissions }})</span>
                                @else
                                    <span class="badge">{{ $role->permissions_count }} / {{ $totalPermissions }}</span>
                                @endif
                            </td>
                            <td class="text-muted">{{ $role->users_count }}</td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    @allows('settings.roles.view')
                                        <a class="btn btn-sm" href="{{ route('admin.roles.edit', $role) }}">
                                            {{ $isSuper ? 'View' : 'Edit' }}
                                        </a>
                                    @endallows

                                    @allows('settings.roles.create')
                                        <form method="POST" action="{{ route('admin.roles.clone', $role) }}"
                                              data-ajax>
                                            @csrf
                                            <button type="submit" class="btn btn-sm" title="Duplicate this role">
                                                Clone
                                            </button>
                                        </form>
                                    @endallows

                                    @allows('settings.roles.delete')
                                        @unless ($isSuper)
                                            {{-- Cancelling has to stop the event as well as
                                                 prevent it: app.js listens on document, so a
                                                 plain `return false` suppresses the normal
                                                 submit and posts it over AJAX anyway. --}}
                                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}"
                                                  data-ajax
                                                  onsubmit="if (! confirm('Delete the role &quot;{{ $role->name }}&quot;? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endunless
                                    @endallows
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty">
                                    <x-icon name="inbox" :size="28" />
                                    <h3>No roles found</h3>
                                    <p class="text-sm">Try a different search term.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
