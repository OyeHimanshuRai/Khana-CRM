{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change - so every write in this
    module ends with a data-refresh-list that re-fetches exactly this.

    No <script> may live here: this markup arrives via innerHTML, which never
    runs its scripts. The page-level behaviour is in index.blade.php.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Template</th>
                <th>Subject</th>
                <th>Category</th>
                <th>Status</th>
                <th class="num">Used</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($templates as $template)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.email.templates.show', $template) }}"
                               data-modal="{{ route('admin.email.templates.show', $template) }}"
                               data-modal-title="{{ $template->name }}"
                               data-modal-sub="Email template"
                               data-modal-size="lg">{{ $template->name }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            <span class="list-ref">{{ $template->slug }}</span>
                            · {{ number_format($template->wordCount()) }} words
                        </span>
                    </td>

                    <td class="text-sm">
                        {{ Str::limit($template->subject, 55) }}

                        @if ($template->variableList()->isNotEmpty())
                            {{-- Which merge tags this body actually uses,
                                 detected on save rather than declared. --}}
                            <div class="text-xs text-muted" style="margin-top:2px">
                                {{ $template->variableList()->implode(' ') }}
                            </div>
                        @endif
                    </td>

                    <td>
                        @if ($template->category)
                            <span class="badge badge-info">{{ $template->category }}</span>
                        @else
                            <span class="text-xs text-muted">Uncategorised</span>
                        @endif
                    </td>

                    <td>
                        @allows('email.templates.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.email.templates.status', $template) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $template->is_active ? 'is-on' : '' }}"
                                        title="{{ $template->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $template->statusLabel() }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $template->statusTone() }}">
                                <span class="badge-dot"></span> {{ $template->statusLabel() }}
                            </span>
                        @endallows
                    </td>

                    <td class="num text-sm">
                        {{ number_format($template->usage_count) }}
                        @if ($template->last_used_at)
                            <div class="text-xs text-muted">{{ $template->last_used_at->diffForHumans() }}</div>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.email.templates.show', $template) }}"
                               data-modal="{{ route('admin.email.templates.show', $template) }}"
                               data-modal-title="{{ $template->name }}"
                               data-modal-sub="Email template"
                               data-modal-size="lg"
                               aria-label="View {{ $template->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('email.templates.edit')
                                <a class="btn btn-icon" href="{{ route('admin.email.templates.edit', $template) }}"
                                   data-modal="{{ route('admin.email.templates.edit', $template) }}"
                                   data-modal-title="Edit Template"
                                   data-modal-sub="{{ $template->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $template->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            <div class="dropdown">
                                <button type="button" class="btn btn-icon" data-dropdown-toggle
                                        aria-expanded="false"
                                        aria-label="More actions for {{ $template->name }}">
                                    <x-icon name="more-vertical" :size="15" />
                                </button>

                                <div class="dropdown-menu" style="min-width:230px">

                                    @allows('email.templates.edit')
                                        @if (filled($template->content))
                                            <a class="dropdown-item"
                                               href="{{ route('admin.email.templates.test', $template) }}"
                                               data-modal="{{ route('admin.email.templates.test', $template) }}"
                                               data-modal-title="Send a Test"
                                               data-modal-sub="{{ $template->name }}">
                                                <x-icon name="mail" :size="15" /> Send a test
                                            </a>
                                        @endif
                                    @endallows

                                    @allows('email.templates.create')
                                        <form method="POST"
                                              action="{{ route('admin.email.templates.duplicate', $template) }}"
                                              data-ajax data-refresh-list>
                                            @csrf
                                            <button type="submit" class="dropdown-item">
                                                <x-icon name="package" :size="15" /> Duplicate
                                            </button>
                                        </form>
                                    @endallows

                                    @allows('email.templates.delete')
                                        <div class="dropdown-divider"></div>

                                        <form method="POST"
                                              action="{{ route('admin.email.templates.destroy', $template) }}"
                                              data-ajax data-refresh-list
                                              onsubmit="return confirm('Delete “{{ $template->name }}”? Campaigns already made from it are unaffected.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="dropdown-item is-danger">
                                                <x-icon name="trash" :size="15" /> Delete template
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
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No templates found</h3>
                            <p class="text-sm">Adjust the filters, or write the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$templates" :per-page="$perPage" :page-sizes="$pageSizes" label="templates" />
