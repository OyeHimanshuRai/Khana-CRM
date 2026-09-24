{{--
    Swappable fragment: the configured printers.

    The "Test" button is the most useful control on the page. "Is the IP
    right, is it switched on, is it on the same network" is three questions
    that one strip of paper answers — and the alternative is finding out
    during service.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Printer</th>
                <th>Prints</th>
                <th>How</th>
                <th>Where</th>
                <th>Last used</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($printers as $printer)
                <tr>
                    <td>
                        <strong>{{ $printer->name }}</strong>
                        <span class="text-xs text-muted" style="display:block">{{ $printer->code }}</span>
                    </td>

                    <td class="text-sm">
                        {{ $printer->kindLabel() }}
                        @if ($printer->station)
                            <span class="text-xs text-muted" style="display:block">{{ $printer->station->name }}</span>
                        @elseif ($printer->is_default)
                            <span class="text-xs text-muted" style="display:block">default for this kind</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $printer->driverLabel() }}
                        @if ($printer->isAutomatic())
                            <span class="text-xs text-muted" style="display:block">
                                no one needs to be at a screen
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $printer->addressLabel() }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $printer->columns }} cols · {{ $printer->copies }}
                            cop{{ $printer->copies === 1 ? 'y' : 'ies' }}
                        </span>
                    </td>

                    <td class="text-sm">
                        @if ($printer->last_used_at)
                            {{ $printer->last_used_at->diffForHumans() }}
                        @else
                            <span class="text-muted">Never</span>
                        @endif
                    </td>

                    <td>
                        @if (! $printer->is_active)
                            <span class="badge badge-muted"><span class="badge-dot"></span> Off</span>
                        @elseif (! $printer->isUsable())
                            {{-- A network printer with no address is somebody's
                                 half-finished thought, and a kitchen that thinks
                                 it has a printer is worse off than one that
                                 knows it has none. --}}
                            <span class="badge badge-danger"><span class="badge-dot"></span> No address</span>
                        @else
                            <span class="badge badge-success"><span class="badge-dot"></span> Ready</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('settings.printers.print')
                                @if ($printer->isAutomatic())
                                    <form method="POST" action="{{ route('admin.printers.test', $printer) }}"
                                          data-ajax style="display:inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm">Test</button>
                                    </form>
                                @endif
                            @endallows

                            @allows('settings.printers.edit')
                                <a class="btn btn-icon" href="{{ route('admin.printers.edit', $printer) }}"
                                   data-modal="{{ route('admin.printers.edit', $printer) }}"
                                   data-modal-title="Edit Printer"
                                   data-modal-sub="{{ $printer->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $printer->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('settings.printers.delete')
                                <form method="POST" action="{{ route('admin.printers.destroy', $printer) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove “{{ $printer->name }}”? Anything routed to it falls back to the default, or to browser printing.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $printer->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="file" :size="28" />
                            <h3>No printers configured</h3>
                            <p class="text-sm">
                                Nothing is broken — every print screen still uses the browser's own dialog,
                                exactly as before. Add a network printer when you want a kitchen ticket to
                                come out beside the tandoor with nobody pressing anything.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$printers" :per-page="$perPage" :page-sizes="$pageSizes" label="printers" />
