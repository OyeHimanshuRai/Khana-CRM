@extends('admin.layouts.app')

@section('title', $tenant->name)

@section('content')
    <x-page-header
        :title="$tenant->name"
        subtitle="Company profile, contact details and branches."
        :crumbs="['Settings' => null, 'Companies' => route('admin.tenants.index'), $tenant->name => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.tenants.index') }}">Companies</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-body">
            @include('admin.tenants._show', ['tenant' => $tenant, 'branches' => $branches, 'standalone' => true])
        </div>
    </div>
@endsection
