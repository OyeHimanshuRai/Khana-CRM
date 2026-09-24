{{--
    Swappable fragment: what has been sent and what is going out.

    Progress is read off the stored tallies rather than counted per row — the
    list shows every campaign at once, and counting three ways per row would
    be a query storm on the page a manager opens most.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Campaign</th>
                <th>Channel</th>
                <th>Audience</th>
                <th>Progress</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($campaigns as $campaign)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.campaigns.show', $campaign) }}"
                               data-modal="{{ route('admin.campaigns.show', $campaign) }}"
                               data-modal-title="{{ $campaign->name }}"
                               data-modal-sub="{{ $campaign->channelLabel() }} campaign"
                               data-modal-size="lg">{{ $campaign->name }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ Str::limit($campaign->body, 60) }}
                        </span>
                    </td>

                    <td class="text-sm">{{ $campaign->channelLabel() }}</td>

                    <td class="text-sm">
                        {{ $campaign->audience_count > 0 ? number_format($campaign->audience_count) : '—' }}
                    </td>

                    <td class="text-sm">
                        @if ($campaign->audience_count > 0)
                            {{ number_format($campaign->sent_count) }} sent
                            @if ($campaign->failed_count > 0)
                                <span class="text-xs" style="display:block; color:var(--danger)">
                                    {{ number_format($campaign->failed_count) }} failed
                                </span>
                            @elseif ($campaign->status === App\Models\Campaign::SENDING)
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $campaign->progress() }}% done
                                </span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-{{ $campaign->statusTone() }}">
                            <span class="badge-dot"></span> {{ $campaign->statusLabel() }}
                        </span>
                        @if ($campaign->scheduled_for && $campaign->status === App\Models\Campaign::SCHEDULED)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $campaign->scheduled_for->format('j M, g:i a') }}
                            </span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.campaigns.show', $campaign) }}"
                               data-modal="{{ route('admin.campaigns.show', $campaign) }}"
                               data-modal-title="{{ $campaign->name }}"
                               data-modal-sub="{{ $campaign->channelLabel() }} campaign"
                               data-modal-size="lg"
                               aria-label="Open {{ $campaign->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('crm.campaigns.edit')
                                @if ($campaign->isEditable())
                                    <a class="btn btn-icon" href="{{ route('admin.campaigns.edit', $campaign) }}"
                                       data-modal="{{ route('admin.campaigns.edit', $campaign) }}"
                                       data-modal-title="Edit Campaign"
                                       data-modal-sub="{{ $campaign->name }}"
                                       data-modal-size="lg"
                                       aria-label="Edit {{ $campaign->name }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endif
                            @endallows

                            @allows('crm.campaigns.approve')
                                @unless ($campaign->isFinished())
                                    <form method="POST" action="{{ route('admin.campaigns.cancel', $campaign) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Stop “{{ $campaign->name }}”? Anything already sent cannot be recalled.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm is-danger">Stop</button>
                                    </form>
                                @endunless
                            @endallows

                            @allows('crm.campaigns.delete')
                                @if ($campaign->status !== App\Models\Campaign::SENDING)
                                    <form method="POST" action="{{ route('admin.campaigns.destroy', $campaign) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete “{{ $campaign->name }}” and its recipient list?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger" aria-label="Delete">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endif
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="mail" :size="28" />
                            <h3>No campaigns yet</h3>
                            <p class="text-sm">
                                A campaign is one message to a group of customers — regulars who have not
                                been in for a while, or everybody holding loyalty points.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$campaigns" :per-page="$perPage" :page-sizes="$pageSizes" label="campaigns" />
