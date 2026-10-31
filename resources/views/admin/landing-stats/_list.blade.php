{{-- Swappable fragment: trust numbers (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:60px">Order</th>
                <th>Label</th>
                <th>Shows</th>
                <th>Where from</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-sm text-muted">{{ $row->sort_order }}</td>

                    <td class="text-sm"><strong>{{ $row->label }}</strong></td>

                    {{-- The value as the page will actually print it — counted
                         now, for a live row. --}}
                    <td class="text-sm"><strong>{{ $row->display() }}</strong></td>

                    <td class="text-sm">
                        @if ($row->isLive())
                            <span class="badge badge-success">Counted</span>
                            <span class="text-xs text-muted" style="display:block">
                                {{ $row->sourceLabel() }}
                            </span>
                        @else
                            {{-- Not an error, but worth flagging: a typed number
                                 is right on the day it is typed and slowly stops
                                 being. --}}
                            <span class="badge badge-warning">Typed by hand</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-{{ $row->is_active ? 'success' : 'default' }}">
                            {{ $row->is_active ? 'Showing' : 'Hidden' }}
                        </span>
                    </td>

                    <td class="col-action">
                        @include('admin.landing-stats._row-actions', [
                            'row' => $row,
                            'routeBase' => 'admin.landing-stats',
                            'can' => 'content.stats',
                            'label' => $row->label,
                            'modalTitle' => 'Edit number',
                        ])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="chart" :size="28" />
                            <h3>No trust numbers yet</h3>
                            <p class="text-sm">
                                Add one, and prefer a counted source: a number the software
                                works out cannot be stale by the time somebody reads it.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="numbers" />
