{{-- Swappable fragment: product screenshots (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:60px">Order</th>
                <th style="width:120px">Image</th>
                <th>Title</th>
                <th>Caption</th>
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
                            <img src="{{ $row->imageUrl() }}" alt="{{ $row->title }}"
                                 style="height:44px;width:80px;object-fit:cover;border-radius:6px">
                        @else
                            {{-- Skipped by the landing page: a screenshot section
                                 is its screenshots. --}}
                            <span class="badge badge-warning">No image</span>
                        @endif
                    </td>

                    <td class="text-sm"><strong>{{ $row->title }}</strong></td>
                    <td class="text-sm">{{ Str::limit($row->caption, 70) ?: '—' }}</td>

                    <td>
                        <span class="badge badge-{{ $row->is_active ? 'success' : 'default' }}">
                            {{ $row->is_active ? 'Showing' : 'Hidden' }}
                        </span>
                    </td>

                    <td class="col-action">
                        @include('admin.landing-stats._row-actions', [
                            'row' => $row,
                            'routeBase' => 'admin.showcases',
                            'can' => 'content.showcases',
                            'label' => $row->title,
                            'modalTitle' => 'Edit screenshot',
                        ])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="camera" :size="28" />
                            <h3>No screenshots yet</h3>
                            <p class="text-sm">Pictures of the POS, the kitchen board, a report.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="screenshots" />
