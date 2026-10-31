{{-- Swappable fragment: integrations (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:60px">Order</th>
                <th style="width:90px">Logo</th>
                <th>Name</th>
                <th>Category</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-sm text-muted">{{ $row->sort_order }}</td>

                    <td>
                        @if ($row->imageUrl())
                            <img src="{{ $row->imageUrl() }}" alt="{{ $row->name }}"
                                 style="height:26px;width:auto;object-fit:contain">
                        @else
                            {{-- A row with no logo still renders on the page, as
                                 a wordmark. Said here so it does not look broken. --}}
                            <span class="text-xs text-muted">wordmark</span>
                        @endif
                    </td>

                    <td class="text-sm"><strong>{{ $row->name }}</strong></td>
                    <td class="text-sm">{{ $row->category ?: '—' }}</td>

                    <td>
                        <span class="badge badge-{{ $row->is_active ? 'success' : 'default' }}">
                            {{ $row->is_active ? 'Showing' : 'Hidden' }}
                        </span>
                    </td>

                    <td class="col-action">
                        @include('admin.landing-stats._row-actions', [
                            'row' => $row,
                            'routeBase' => 'admin.integrations',
                            'can' => 'content.integrations',
                            'label' => $row->name,
                            'modalTitle' => 'Edit integration',
                        ])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="zap" :size="28" />
                            <h3>No integrations listed</h3>
                            <p class="text-sm">Payment gateways, delivery platforms, accounting tools.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="integrations" />
