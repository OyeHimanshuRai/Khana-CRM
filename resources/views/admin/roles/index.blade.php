@extends('admin.layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
    <x-page-header
        title="Roles & Permissions"
        subtitle="{{ $roles->count() }} {{ $scope === 'platform' ? 'platform' : 'restaurant' }} role(s) across {{ $totalPermissions }} declared permissions."
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
        <nav class="tabs" aria-label="Role type">
            <a class="tab {{ $scope === 'platform' ? 'is-active' : '' }}" href="{{ route('admin.roles.index') }}">Platform roles</a>
            <a class="tab {{ $scope === 'restaurant' ? 'is-active' : '' }}" href="{{ route('admin.roles.index', ['scope' => 'restaurant']) }}">Restaurant roles</a>
        </nav>

        <div class="card-header">
            <form method="GET" class="pmatrix-search" style="max-width:320px">
                @if ($scope === 'restaurant')
                    <input type="hidden" name="scope" value="restaurant">
                @endif
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
                                    <span class="badge badge-success">All platform ({{ $platformPermissions }})</span>
                                @else
                                    <span class="badge">{{ $role->permissions_count }} / {{ $totalPermissions }}</span>
                                @endif
                            </td>
                            <td class="text-muted">{{ $role->users_count }}</td>
                            <td>
                                {{-- Bare icons, like every other listing: three bordered text buttons on
                                     thirteen rows out-shouted the roles they act on. --}}
                                <div class="row-actions">
                                    @allows('settings.roles.view')
                                        <a class="btn btn-icon" href="{{ route('admin.roles.edit', $role) }}"
                                           title="{{ $isSuper ? 'View' : 'Edit' }}"
                                           aria-label="{{ $isSuper ? 'View' : 'Edit' }} {{ $role->name }}">
                                            <x-icon :name="$isSuper ? 'search' : 'edit'" :size="15" />
                                        </a>
                                    @endallows

                                    @allows('settings.roles.create')
                                        <form method="POST" action="{{ route('admin.roles.clone', $role) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-icon" title="Duplicate this role"
                                                    aria-label="Duplicate {{ $role->name }}">
                                                <x-icon name="copy" :size="15" />
                                            </button>
                                        </form>
                                    @endallows

                                    @allows('settings.roles.delete')
                                        @unless ($isSuper)
                                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}"
                                                  onsubmit="return confirm('Delete the role &quot;{{ $role->name }}&quot;? This cannot be undone.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-icon is-danger" title="Delete"
                                                        aria-label="Delete {{ $role->name }}">
                                                    <x-icon name="trash" :size="15" />
                                                </button>
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
