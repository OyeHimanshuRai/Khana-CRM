@extends('khana::layouts.app')

@section('title', 'Businesses')

@section('content')
    <x-khana::page-header title="Businesses" subtitle="Every business on the platform" />

    <form method="GET" class="list-toolbar" style="margin-bottom:16px">
        <input class="form-control list-search" name="q" value="{{ $search }}" placeholder="Search businesses…" style="max-width:280px">
        <button class="btn btn-ghost btn-sm">Search</button>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Outlets</th><th>People</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($tenants as $tenant)
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong></td>
                            <td>{{ $tenant->outlets_count }}</td>
                            <td>{{ $tenant->users_count }}</td>
                            <td>
                                <span class="badge badge-{{ $tenant->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($tenant->status) }}</span>
                            </td>
                            <td class="col-action"><a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-sm btn-ghost">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><span class="text-muted">No businesses found.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="list-footer" style="margin-top:14px">{{ $tenants->links() }}</div>
@endsection
