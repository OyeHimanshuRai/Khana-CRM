{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Page</th>
                <th>Address</th>
                <th>Footer</th>
                <th class="num">Order</th>
                <th>Status</th>
                <th>Updated</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($pages as $page)
                <tr>
                    <td><strong>{{ $page->title }}</strong></td>

                    <td class="text-sm">
                        @if ($page->is_active)
                            <a href="{{ route('page.show', $page->slug) }}" target="_blank" rel="noopener">/p/{{ $page->slug }}</a>
                        @else
                            <span class="text-muted">/p/{{ $page->slug }}</span>
                        @endif
                    </td>

                    <td class="text-sm">{{ $page->show_in_footer ? 'Yes' : '—' }}</td>
                    <td class="num text-muted text-sm">{{ $page->sort_order }}</td>

                    <td>
                        @allows('settings.pages.edit')
                            <form method="POST" action="{{ route('admin.pages.status', $page) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $page->is_active ? 'is-on' : '' }}"
                                        title="{{ $page->is_active ? 'Click to hide' : 'Click to publish' }}">
                                    <span class="badge-dot"></span>
                                    {{ $page->is_active ? 'Published' : 'Hidden' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $page->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $page->is_active ? 'Published' : 'Hidden' }}
                            </span>
                        @endallows
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $page->updated_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('settings.pages.edit')
                                <a class="btn btn-icon" href="{{ route('admin.pages.edit', $page) }}"
                                   data-modal="{{ route('admin.pages.edit', $page) }}"
                                   data-modal-title="Edit page"
                                   data-modal-sub="{{ Str::limit($page->title, 60) }}"
                                   data-modal-size="lg"
                                   aria-label="Edit this page">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('settings.pages.delete')
                                <form method="POST" action="{{ route('admin.pages.destroy', $page) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete this page? Anyone with its link will see “not found”.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger" aria-label="Delete this page">
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
                            <x-icon name="inbox" :size="28" />
                            <h3>No pages yet</h3>
                            <p class="text-sm">
                                Add your Privacy Policy, Terms and Refund &amp; Cancellation Policy.
                                Payment providers check that a site publishes them.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$pages" :per-page="$perPage" :page-sizes="$pageSizes" label="pages" />
