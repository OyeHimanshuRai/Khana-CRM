@extends('admin.layouts.app')

@section('title', 'License History')

@section('content')
    <x-page-header title="License History" subtitle="Every change made to every license, newest first"
        :crumbs="['Settings' => null, 'Licenses' => route('admin.licenses.index'), 'History' => null]">
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.licenses.index') }}">Back to licenses</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="hist-q" class="sr-only">Search history</label>
                <input id="hist-q" type="search" name="q" value="{{ $search }}" placeholder="Search the history…" autocomplete="off">
            </div>
            <div class="list-filters"><button type="submit" class="btn btn-sm">Search</button></div>
        </form>

        <div class="table-wrap">
            <table class="table table-list">
                <thead><tr><th>When</th><th>License</th><th>Customer</th><th>What happened</th><th>By</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-sm">{{ $log->created_at?->format('j M Y, g:i a') }}</td>
                        <td class="text-sm"><code>{{ $log->license?->key_hint ?? '—' }}</code></td>
                        <td class="text-sm">{{ $log->tenant?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $log->description }}</td>
                        <td class="text-sm">{{ $log->user?->name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty"><h3>No history yet</h3></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$logs" label="entries" />
    </div>
@endsection
