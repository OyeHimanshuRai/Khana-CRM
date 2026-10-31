{{-- Swappable fragment: see admin/users/_list.blade.php. --}}
<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Event</th>
                <th>Description</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="text-muted" style="white-space:nowrap">
                        <span title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</span>
                    </td>
                    <td>
                        <div class="user-cell">
                            <span class="avatar avatar-sm" aria-hidden="true">
                                {{ Str::upper(Str::substr($log->user_name ?? '?', 0, 2)) }}
                            </span>
                            <span><strong>{{ $log->user_name ?? 'System' }}</strong></span>
                        </div>
                    </td>
                    <td>
                        <span class="badge {{ str_contains($log->event, 'delete') ? 'badge-danger' : 'badge-info' }}">
                            {{ $log->event }}
                        </span>
                    </td>
                    <td>
                        {{ $log->description }}

                        @if ($log->properties)
                            @php
                                $added = $log->properties['added'] ?? [];
                                $removed = $log->properties['removed'] ?? [];
                            @endphp

                            @if ($added || $removed)
                                <div class="text-xs text-muted" style="margin-top:3px">
                                    @if ($added)
                                        <span style="color:var(--success)">+{{ count($added) }}</span>
                                    @endif
                                    @if ($removed)
                                        <span style="color:var(--danger)">−{{ count($removed) }}</span>
                                    @endif
                                    permission change(s)
                                </div>
                            @endif
                        @endif
                    </td>
                    <td class="text-muted text-xs">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No activity recorded</h3>
                            <p class="text-sm">Role and user changes will appear here.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$logs" :per-page="$perPage" :page-sizes="$pageSizes" label="events" />
