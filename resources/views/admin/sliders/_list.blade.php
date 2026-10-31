{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change - so every write in this
    module ends with a data-refresh-list that re-fetches exactly this.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Image</th>
                <th>Title</th>
                <th>Layout</th>
                <th class="num">Item No</th>
                <th>Desktop</th>
                <th>Mobile</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($sliders as $slider)
                @php $thumb = $slider->thumbnailUrl(); @endphp
                <tr>
                    <td>
                        <span class="slider-thumb">
                            @if ($thumb)
                                <img src="{{ $thumb }}" alt="{{ $slider->title }}">
                            @else
                                {{-- Video-only slides have no still to show
                                     without ffmpeg, so the icon stands in. --}}
                                <x-icon name="package" :size="17" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.sliders.show', $slider) }}"
                               data-modal="{{ route('admin.sliders.show', $slider) }}"
                               data-modal-title="{{ $slider->title }}"
                               data-modal-sub="Slider details"
                               data-modal-size="lg">{{ $slider->title }}</a>
                        </strong>
                        @if ($slider->redirect_url)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($slider->redirect_url, 60) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="badge badge-info">{{ $slider->layoutLabel() }}</span></td>

                    <td class="num text-muted text-sm">{{ $slider->item_no }}</td>

                    @foreach (['desktop', 'mobile'] as $device)
                        @php $summary = $slider->mediaSummary($device); @endphp
                        <td class="text-sm">
                            @if ($summary === 'None')
                                <span class="text-xs text-muted">None</span>
                            @else
                                <span class="badge {{ $summary === 'Video' ? 'badge-warning' : 'badge-success' }}">
                                    {{ $summary }}
                                </span>
                            @endif
                        </td>
                    @endforeach

                    <td>
                        @allows('content.sliders.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.sliders.status', $slider) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $slider->is_active ? 'is-on' : '' }}"
                                        title="{{ $slider->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $slider->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $slider->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $slider->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.sliders.show', $slider) }}"
                               data-modal="{{ route('admin.sliders.show', $slider) }}"
                               data-modal-title="{{ $slider->title }}"
                               data-modal-sub="Slider details"
                               data-modal-size="lg"
                               aria-label="View {{ $slider->title }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.sliders.edit')
                                <a class="btn btn-icon" href="{{ route('admin.sliders.edit', $slider) }}"
                                   data-modal="{{ route('admin.sliders.edit', $slider) }}"
                                   data-modal-title="Edit Slider"
                                   data-modal-sub="{{ $slider->title }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $slider->title }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            <div class="dropdown">
                                <button type="button" class="btn btn-icon" data-dropdown-toggle
                                        aria-expanded="false" aria-label="More actions for {{ $slider->title }}">
                                    <x-icon name="more-vertical" :size="15" />
                                </button>

                                <div class="dropdown-menu" style="min-width:200px">
                                    <a class="dropdown-item" href="{{ route('admin.sliders.show', $slider) }}"
                                       data-modal="{{ route('admin.sliders.show', $slider) }}"
                                       data-modal-title="{{ $slider->title }}"
                                       data-modal-sub="Slider details"
                                       data-modal-size="lg">
                                        <x-icon name="search" :size="15" /> View
                                    </a>

                                    @allows('content.sliders.edit')
                                        <a class="dropdown-item" href="{{ route('admin.sliders.edit', $slider) }}"
                                           data-modal="{{ route('admin.sliders.edit', $slider) }}"
                                           data-modal-title="Edit Slider"
                                           data-modal-sub="{{ $slider->title }}"
                                           data-modal-size="lg">
                                            <x-icon name="edit" :size="15" /> Edit
                                        </a>
                                    @endallows

                                    @allows('content.sliders.create')
                                        <form method="POST" action="{{ route('admin.sliders.duplicate', $slider) }}"
                                              data-ajax data-refresh-list>
                                            @csrf
                                            <button type="submit" class="dropdown-item">
                                                <x-icon name="file" :size="15" /> Duplicate
                                            </button>
                                        </form>
                                    @endallows

                                    @allows('content.sliders.delete')
                                        <div class="dropdown-divider"></div>

                                        <form method="POST" action="{{ route('admin.sliders.destroy', $slider) }}"
                                              data-ajax data-refresh-list
                                              onsubmit="return confirm('Delete “{{ $slider->title }}”? Its media is deleted too, and this cannot be undone.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="dropdown-item is-danger">
                                                <x-icon name="trash" :size="15" /> Delete
                                            </button>
                                        </form>
                                    @endallows
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No sliders found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$sliders" :per-page="$perPage" :page-sizes="$pageSizes" label="sliders" />
