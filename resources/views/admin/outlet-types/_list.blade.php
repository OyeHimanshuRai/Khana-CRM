{{-- Swappable fragment: outlet types (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:60px">Order</th>
                <th style="width:70px">Icon</th>
                <th>Name</th>
                <th>Description</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-sm text-muted">{{ $row->sort_order }}</td>

                    <td>
                        {{-- iconName() falls back, so a renamed icon cannot
                             reach the component with an unknown key. --}}
                        <x-icon :name="$row->iconName()" :size="20" />
                    </td>

                    <td class="text-sm"><strong>{{ $row->name }}</strong></td>
                    <td class="text-sm">{{ Str::limit($row->blurb, 80) ?: '—' }}</td>

                    <td>
                        <span class="badge badge-{{ $row->is_active ? 'success' : 'default' }}">
                            {{ $row->is_active ? 'Showing' : 'Hidden' }}
                        </span>
                    </td>

                    <td class="col-action">
                        @include('admin.landing-stats._row-actions', [
                            'row' => $row,
                            'routeBase' => 'admin.outlet-types',
                            'can' => 'content.outlet_types',
                            'label' => $row->name,
                            'modalTitle' => 'Edit outlet type',
                        ])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="building" :size="28" />
                            <h3>No outlet types yet</h3>
                            <p class="text-sm">
                                Fine dining, QSR, cafe, cloud kitchen, bakery, bar. A reader
                                recognising their own format is most of the sale.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="outlet types" />
