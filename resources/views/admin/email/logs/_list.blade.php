{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change.

    No <script> may live here: this markup arrives via innerHTML, which never
    runs its scripts. The page-level behaviour is in index.blade.php.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Recipient</th>
                <th>Subject</th>
                <th>Type</th>
                <th>Status</th>
                <th>When</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>
                        <strong style="display:block">
                            <a href="{{ route('admin.email.logs.show', $log) }}"
                               data-modal="{{ route('admin.email.logs.show', $log) }}"
                               data-modal-title="{{ $log->to_email }}"
                               data-modal-sub="Email log">{{ $log->to_email }}</a>
                        </strong>

                        @if ($log->to_name)
                            <span class="text-xs text-muted">{{ $log->to_name }}</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ Str::limit($log->subject ?: '—', 60) }}

                        @if ($log->error)
                            {{-- The whole point of the screen when something
                                 went wrong: which message, and why. --}}
                            <div class="text-xs" style="color:var(--danger);margin-top:2px">
                                {{ Str::limit($log->error, 80) }}
                            </div>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-info">{{ $log->kindLabel() }}</span>
                    </td>

                    <td>
                        <span class="badge {{ $log->statusTone() }}">
                            <span class="badge-dot"></span> {{ $log->statusLabel() }}
                        </span>

                        @if ($log->isStuck())
                            {{-- Neither listener finished it, which in practice
                                 means the process died mid-send. --}}
                            <div class="text-xs text-muted" style="margin-top:2px">Never resolved</div>
                        @endif
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ ($log->sent_at ?? $log->created_at)?->format('d M Y, H:i') ?? '—' }}
                        <div class="text-xs">{{ $log->created_at?->diffForHumans() }}</div>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.email.logs.show', $log) }}"
                               data-modal="{{ route('admin.email.logs.show', $log) }}"
                               data-modal-title="{{ $log->to_email }}"
                               data-modal-sub="Email log"
                               aria-label="View the log entry for {{ $log->to_email }}">
                                <x-icon name="search" :size="15" />
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No messages found</h3>
                            <p class="text-sm">
                                Every email the app sends is recorded here. Adjust the filters, or
                                send one and come back.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$logs" :per-page="$perPage" :page-sizes="$pageSizes" label="messages" />
