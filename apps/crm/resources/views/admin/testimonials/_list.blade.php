{{-- Swappable fragment: testimonials (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:60px">Order</th>
                <th>Who</th>
                <th>Quote</th>
                <th>Rating</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-sm text-muted">{{ $row->sort_order }}</td>

                    <td class="text-sm">
                        <strong>{{ $row->author_name }}</strong>
                        @if ($row->attribution())
                            <span class="text-xs text-muted" style="display:block">{{ $row->attribution() }}</span>
                        @endif
                    </td>

                    <td class="text-sm">{{ Str::limit($row->quote, 90) }}</td>

                    <td class="text-sm">
                        {{-- A missing rating is normal, not a gap to fill. --}}
                        {{ $row->hasRating() ? $row->rating.' / 5' : '—' }}
                    </td>

                    <td>
                        <span class="badge badge-{{ $row->is_active ? 'success' : 'default' }}">
                            {{ $row->is_active ? 'Showing' : 'Hidden' }}
                        </span>
                    </td>

                    <td class="col-action">
                        @include('admin.landing-stats._row-actions', [
                            'row' => $row,
                            'routeBase' => 'admin.testimonials',
                            'can' => 'content.testimonials',
                            'label' => $row->author_name,
                            'modalTitle' => 'Edit testimonial',
                        ])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="star" :size="28" />
                            <h3>No testimonials yet</h3>
                            <p class="text-sm">
                                The section stays off the landing page until there is one to show.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="testimonials" />
