{{--
    Swappable fragment: what guests said.

    Nothing here edits what a guest wrote. The only write on this screen is a
    reply — a complaint that can be quietly softened is not feedback.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>When</th>
                <th>Rating</th>
                <th>From</th>
                <th>Said</th>
                <th>Reply</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($feedback as $item)
                <tr>
                    <td class="text-sm">
                        {{ $item->created_at?->format('j M, g:i a') }}
                        @if ($item->session?->table)
                            <span class="text-xs text-muted" style="display:block">
                                Table {{ $item->session->table->name }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-{{ $item->tone() }}">
                            {{ $item->rating }} / 5
                        </span>
                        @if ($item->food_rating || $item->service_rating)
                            <span class="text-xs text-muted" style="display:block">
                                @if ($item->food_rating) food {{ $item->food_rating }} @endif
                                @if ($item->service_rating) · service {{ $item->service_rating }} @endif
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $item->fromLabel() }}
                        @if ($item->guest_mobile)
                            <span class="text-xs text-muted" style="display:block">{{ $item->guest_mobile }}</span>
                        @endif
                    </td>

                    <td class="text-sm">{{ Str::limit($item->comment, 70) ?: '—' }}</td>

                    <td class="text-sm">
                        @if ($item->isAnswered())
                            <span class="badge badge-success">Answered</span>
                            <span class="text-xs text-muted" style="display:block">
                                {{ $item->responder?->name }}, {{ $item->responded_at->diffForHumans() }}
                            </span>
                        @elseif ($item->rating <= App\Models\Feedback::POOR)
                            <span class="badge badge-danger">Waiting</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.feedback.show', $item) }}"
                               data-modal="{{ route('admin.feedback.show', $item) }}"
                               data-modal-title="{{ $item->rating }} out of 5"
                               data-modal-sub="{{ $item->fromLabel() }}"
                               data-modal-size="lg"
                               aria-label="Open feedback from {{ $item->fromLabel() }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('crm.feedback.delete')
                                <form method="POST" action="{{ route('admin.feedback.destroy', $item) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete this feedback? What a guest said is not usually something to remove.')">
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
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>Nothing to answer</h3>
                            <p class="text-sm">
                                Guests are offered a one-tap rating at the bottom of their order screen.
                                Untick "Needs a reply" to see everything.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$feedback" :per-page="$perPage" :page-sizes="$pageSizes" label="ratings" />
