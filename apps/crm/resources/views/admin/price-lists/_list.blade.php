{{--
    Swappable fragment: the price lists and whether each is running.

    "Running now" is worked out on render rather than stored. A stored flag
    would need something to run to clear it, and a happy hour that stayed on
    because a cron missed is the expensive direction of that bug.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>List</th>
                <th>When</th>
                <th>Dishes</th>
                <th>Priority</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($lists as $list)
                @php $running = $runningNow->contains($list->id); @endphp
                <tr @if ($running) style="background: var(--success-soft)" @endif>
                    <td>
                        <strong>{{ $list->name }}</strong>
                        <span class="text-xs text-muted" style="display:block">{{ $list->code }}</span>
                    </td>

                    <td class="text-sm">
                        {{ $list->windowLabel() }}
                        @if ($list->starts_on || $list->ends_on)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $list->starts_on?->format('j M') ?? 'any' }}
                                – {{ $list->ends_on?->format('j M Y') ?? 'no end' }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">{{ number_format($list->items_count) }}</td>

                    <td class="text-sm">{{ $list->priority }}</td>

                    <td>
                        @if (! $list->is_active)
                            <span class="badge badge-muted"><span class="badge-dot"></span> Off</span>
                        @elseif ($running)
                            {{-- The thing somebody opening this at four o'clock
                                 actually wants to know. --}}
                            <span class="badge badge-success"><span class="badge-dot"></span> Running now</span>
                        @else
                            <span class="badge badge-info"><span class="badge-dot"></span> On, outside its window</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('inventory.price_lists.edit')
                                <form method="POST" action="{{ route('admin.price-lists.toggle', $list) }}"
                                      data-ajax data-refresh-list style="display:inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm">
                                        {{ $list->is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                </form>

                                <a class="btn btn-icon" href="{{ route('admin.price-lists.edit', $list) }}"
                                   aria-label="Edit {{ $list->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.price_lists.delete')
                                <form method="POST" action="{{ route('admin.price-lists.destroy', $list) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $list->name }}”? Prices go back to normal immediately.')">
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
                            <x-icon name="tag" :size="28" />
                            <h3>No price lists</h3>
                            <p class="text-sm">
                                Every dish is charged at its menu price. Make a list when you want
                                something to cost less at certain hours — half price on beer between
                                four and seven, say.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
