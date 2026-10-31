{{--
    Swappable fragment: the support queue (§2).

    The Company column only appears for the desk. For a restaurant every row
    would say the same thing — their own name — which is a column of noise on
    a screen that is already narrow on a tablet.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Ticket</th>
                @if ($canManage)
                    <th>Company</th>
                @endif
                <th>Subject</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Last activity</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tickets as $ticket)
                <tr>
                    <td class="text-sm">
                        <span class="list-ref">{{ $ticket->reference }}</span>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $ticket->created_at?->format('j M, g:i a') }}
                        </span>
                    </td>

                    @if ($canManage)
                        <td class="text-sm">
                            {{ $ticket->tenant?->name ?? '—' }}
                            @if ($ticket->shop)
                                <span class="text-xs text-muted" style="display:block">{{ $ticket->shop->name }}</span>
                            @endif
                        </td>
                    @endif

                    <td class="text-sm">
                        {{ Str::limit($ticket->subject, 60) }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $ticket->categoryLabel() }}
                            @if ($canManage && $ticket->assignee)
                                · {{ $ticket->assignee->name }}
                            @elseif ($canManage)
                                · unassigned
                            @endif
                        </span>
                    </td>

                    <td>
                        <span class="badge badge-{{ $ticket->priorityTone() }}">{{ $ticket->priorityLabel() }}</span>
                    </td>

                    <td>
                        <span class="badge badge-{{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span>
                    </td>

                    <td class="text-sm">
                        {{ $ticket->lastMovedAt()->diffForHumans() }}
                        @if ($ticket->firstResponseMinutes() !== null)
                            <span class="text-xs text-muted" style="display:block">
                                answered in
                                @if ($ticket->firstResponseMinutes() < 60)
                                    {{ $ticket->firstResponseMinutes() }} min
                                @else
                                    {{ round($ticket->firstResponseMinutes() / 60, 1) }} h
                                @endif
                            </span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.support.show', $ticket) }}"
                               aria-label="Open ticket {{ $ticket->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('support.tickets.delete')
                                <form method="POST" action="{{ route('admin.support.destroy', $ticket) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete {{ $ticket->reference }} and its whole thread? Closing it keeps the history instead.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger" aria-label="Delete">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canManage ? 7 : 6 }}">
                        <div class="empty">
                            <x-icon name="help" :size="28" />
                            <h3>{{ $canManage ? 'Nothing in the queue' : 'No tickets yet' }}</h3>
                            <p class="text-sm">
                                @if ($canManage)
                                    Every company on the platform is quiet, or your filters are narrow.
                                @else
                                    Raise one and the platform team will pick it up. You will see every
                                    reply on this screen.
                                @endif
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$tickets" :per-page="$perPage" :page-sizes="$pageSizes" label="tickets" />
