@extends('khana::layouts.app')

@section('title', 'Team')

@section('content')
    <x-khana::page-header title="Team" subtitle="The people who work in your business">
        <x-slot:actions>
            <a href="{{ route('team.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> Invite someone
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    @if (session('invite_link'))
        <div class="alert alert-info" style="margin-bottom:16px">
            <div class="alert-body">
                <div class="alert-title">Invitation link</div>
                <div class="text-sm">Send this to the person if the email does not reach them:</div>
                <code style="display:block;margin-top:6px;word-break:break-all">{{ session('invite_link') }}</code>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Contact</th><th>CRM</th><th>ERP</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        @php
                            $crm = $member->access->firstWhere('product', 'crm');
                            $erp = $member->access->firstWhere('product', 'erp');
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $member->name }}</strong>
                                @if ($member->is_owner)<span class="badge badge-brand">Owner</span>@endif
                            </td>
                            <td>{{ $member->email ?? $member->mobile }}</td>
                            <td>{{ $crm ? ($presets['crm'][$crm->role_key]['name'] ?? $crm->role_key) : '—' }}</td>
                            <td>{{ $erp ? ($presets['erp'][$erp->role_key]['name'] ?? $erp->role_key) : '—' }}</td>
                            <td>
                                <span class="badge badge-{{ $member->status === 'active' ? 'success' : ($member->status === 'invited' ? 'info' : 'danger') }}">
                                    {{ ucfirst($member->status) }}
                                </span>
                            </td>
                            <td class="col-action">
                                @unless ($member->is_owner)
                                    <a href="{{ route('team.edit', $member) }}" class="btn btn-sm btn-ghost">Edit</a>
                                    @if ($member->status !== 'deactivated')
                                        <form method="POST" action="{{ route('team.deactivate', $member) }}" style="display:inline"
                                              onsubmit="return confirm('Deactivate {{ $member->name }}? They will be signed out of every product.')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-ghost is-danger">Deactivate</button>
                                        </form>
                                    @endif
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
