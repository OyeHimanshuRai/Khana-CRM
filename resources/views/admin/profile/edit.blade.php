@extends('admin.layouts.app')

@section('title', 'Your Profile')

@section('content')
    <x-page-header
        title="Your Profile"
        :subtitle="$user->email"
        :crumbs="['Account' => null, 'Profile' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.profile.notifications') }}">
                <x-icon name="bell" :size="15" /> Notification settings
            </a>
            <span class="badge {{ $user->is_admin ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span>
                {{ $user->is_admin ? 'Panel access allowed' : 'Panel access blocked' }}
            </span>
        </x-slot:actions>
    </x-page-header>

    {{-- Same fragment the header's modal loads, so the two can never drift. --}}
    @include('admin.profile._form')
@endsection
