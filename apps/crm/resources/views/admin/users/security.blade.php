@extends('admin.layouts.app')

@section('title', $user->name.' — Security')

@section('content')
    <x-page-header
        :title="$user->name"
        subtitle="Sessions, login history and IP blocks."
        :crumbs="['Settings' => null, 'Users' => route('admin.users.index'), 'Security' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.users.index') }}">Back to users</a>
            @allows('settings.users.edit')
                <a class="btn btn-sm" href="{{ route('admin.users.edit', $user) }}">Edit user</a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    {{-- Same fragment the list's modal loads, so the two can never drift. --}}
    @include('admin.users._security')
@endsection
