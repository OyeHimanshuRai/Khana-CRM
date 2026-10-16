@extends('layouts.app')

@section('title', 'Roles')

@php
    use App\Support\Roles;

    // What a role opens, said in a line: the first few jobs and how many more.
    $summary = function (array $can) {
        if ($can === ['*']) {
            return 'Everything, including settings and roles';
        }

        $labels = array_map(fn (string $ability) => Roles::ABILITIES[$ability] ?? $ability, $can);

        return implode(' · ', array_slice($labels, 0, 3)).(count($labels) > 3 ? ' · and '.(count($labels) - 3).' more' : '');
    };
@endphp

@section('page')
    <x-khana::page-header title="Roles"
        subtitle="What each job may do in ERP. Who holds a role is set on the team screen in Restro One Accounts.">
        <x-slot:actions>
            @if ($teamUrl)
                <a href="{{ $teamUrl }}" class="btn">Team in Accounts</a>
            @endif
            <a href="{{ route('roles.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> New role
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">Your own roles</h2></div>

        @if ($made->isEmpty())
            <x-khana::empty icon="users" title="None yet"
                text="The built-in roles below fit most restaurants. When one does not, copy the nearest and change what should differ." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Role</th><th>May do</th><th style="text-align:right">People</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($made as $role)
                            <tr>
                                <td>
                                    <strong>{{ $role->name }}</strong>
                                    @if ($role->based_on) <div class="text-xs text-muted">Copied from {{ Roles::name($role->based_on) }}</div> @endif
                                </td>
                                <td class="text-sm">{{ $summary((array) $role->abilities) }}</td>
                                <td style="text-align:right">{{ $holders[$role->key] ?? 0 }}</td>
                                <td class="col-action">
                                    <a href="{{ route('roles.edit', $role->id) }}" class="btn btn-sm btn-ghost">Edit</a>
                                    <form method="POST" action="{{ route('roles.destroy', $role->id) }}" style="display:inline"
                                          onsubmit="return confirm('Delete this role?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-ghost is-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Built in</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Role</th><th>May do</th><th style="text-align:right">People</th><th></th></tr></thead>
                <tbody>
                    @foreach ($presets as $key => $preset)
                        <tr>
                            <td><strong>{{ $preset['name'] }}</strong></td>
                            <td class="text-sm">{{ $summary($preset['can']) }}</td>
                            <td style="text-align:right">{{ $holders[$key] ?? 0 }}</td>
                            <td class="col-action">
                                <a href="{{ route('roles.create', ['from' => $key]) }}" class="btn btn-sm btn-ghost">Copy</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer text-sm text-muted">
            Built-in roles are the same for every business and cannot be changed. "People" counts those who have opened ERP.
        </div>
    </div>
@endsection
