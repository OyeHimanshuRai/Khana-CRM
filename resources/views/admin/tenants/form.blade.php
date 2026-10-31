@extends('admin.layouts.app')

@php $isNew = ! $tenant->exists; @endphp

@section('title', $isNew ? 'Add Company' : 'Edit '.$tenant->name)

@section('content')
    <x-page-header
        :title="$isNew ? 'Add Company' : 'Edit '.$tenant->name"
        subtitle="Company identity, tax details, contact information and defaults."
        :crumbs="['Settings' => null, 'Companies' => route('admin.tenants.index'), ($isNew ? 'Add Company' : 'Edit') => null]"
    />

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">{{ $isNew ? 'Company details' : 'Company settings' }}</div>
                <div class="text-xs text-muted">
                    {{ $isNew ? 'Set up the business record.' : 'Changes apply to this company and its branches.' }}
                </div>
            </div>
        </div>
        <div class="card-body">
            @include('admin.tenants._form', ['tenant' => $tenant, 'standalone' => true])
        </div>
    </div>
@endsection
