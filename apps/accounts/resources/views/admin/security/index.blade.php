@extends('khana::layouts.app')

@section('title', 'Security')

@section('content')
    <x-khana::page-header title="Security" subtitle="Sign-ins and blocked addresses across every product" />

    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><span class="card-title">Block an address</span></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.security.block') }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                @csrf
                <div class="field" style="margin:0">
                    <label class="form-label" for="ip_address">IP address</label>
                    <input class="form-control" id="ip_address" name="ip_address" value="{{ old('ip_address') }}" required>
                    @error('ip_address')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field" style="margin:0">
                    <label class="form-label" for="reason">Reason</label>
                    <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}">
                </div>
                <button class="btn btn-primary">Block</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><span class="card-title">Blocked addresses</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Address</th><th>Reason</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                    @forelse ($blocked as $block)
                        <tr>
                            <td>{{ $block->ip_address }}</td>
                            <td>{{ $block->reason ?? '—' }}</td>
                            <td>{{ $block->expires_at?->format('d M Y H:i') ?? 'Never' }}</td>
                            <td class="col-action">
                                <form method="POST" action="{{ route('admin.security.unblock', $block) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-ghost">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><span class="text-muted">Nothing blocked.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Recent sign-ins</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>When</th><th>Who</th><th>Identifier</th><th>Result</th><th>IP</th></tr></thead>
                <tbody>
                    @foreach ($recent as $record)
                        <tr>
                            <td>{{ $record->created_at->format('d M H:i') }}</td>
                            <td>{{ $record->user?->name ?? '—' }}</td>
                            <td>{{ $record->identifier }}</td>
                            <td>
                                <span class="badge badge-{{ $record->status === 'success' ? 'success' : 'danger' }}">{{ ucfirst($record->status) }}</span>
                                @if ($record->reason)<span class="text-xs text-muted">{{ $record->reason }}</span>@endif
                            </td>
                            <td>{{ $record->ip_address }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
