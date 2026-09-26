{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/sort/page change. Nothing outside this file
    is re-rendered, so the toolbar keeps focus and its values.
--}}

@allows('settings.users.delete')
    <div class="bulk-bar" data-bulk-bar hidden>
        <span><strong data-bulk-count>0</strong> selected</span>

        {{-- Cancelling has to stop the event as well as prevent it: app.js
             listens on document, so a plain `return false` suppresses the
             normal submit and posts the form over AJAX anyway. --}}
        <form method="POST" action="{{ route('admin.users.bulk-destroy') }}" class="bulk-bar-form"
              data-ajax data-ajax-reload
              onsubmit="if (! confirm('Delete the selected users? This cannot be undone.')) { event.stopPropagation(); return false; }">
            @csrf
            @method('DELETE')
            {{-- Filled by app.js from the ticked rows. --}}
            <input type="hidden" name="ids" data-bulk-ids value="">
            <button type="submit" class="btn btn-sm btn-danger">
                <x-icon name="trash" :size="14" /> Delete selected
            </button>
        </form>
    </div>
@endallows

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                @allows('settings.users.delete')
                    <th class="col-check">
                        <label class="sr-only" for="check-all">Select all rows</label>
                        <input id="check-all" type="checkbox" data-check-all>
                    </th>
                @endallows
                <th>User</th>
                <th>Roles</th>
                <th>Status</th>
                <th>Presence</th>
                @allows('settings.sessions.view')
                    <th class="num">Sessions</th>
                @endallows
                <th>Last login</th>
                <th>Created</th>
                <th class="col-action">Action</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($users as $user)
                @php
                    $isSelf = $user->is(auth()->user());
                    $online = $user->isOnline();
                @endphp
                <tr>
                    @allows('settings.users.delete')
                        <td class="col-check">
                            @unless ($isSelf)
                                <label class="sr-only" for="check-{{ $user->id }}">Select {{ $user->name }}</label>
                                <input id="check-{{ $user->id }}" type="checkbox"
                                       data-check-item value="{{ $user->id }}">
                            @endunless
                        </td>
                    @endallows

                    <td>
                        <div class="user-cell">
                            <x-avatar :user="$user" />
                            <span>
                                <strong>
                                    <a href="{{ route('admin.users.edit', $user) }}">{{ $user->name }}</a>
                                    @if ($isSelf)
                                        <span class="badge badge-brand" style="margin-left:4px">You</span>
                                    @endif
                                </strong>
                                <span>{{ $user->email }}</span>
                            </span>
                        </div>
                    </td>

                    <td>
                        @forelse ($user->roles as $role)
                            <span class="badge {{ $role->name === \App\Models\User::SUPER_ADMIN ? 'badge-brand' : 'badge-info' }}">
                                {{ $role->name }}
                            </span>
                        @empty
                            <span class="text-xs text-muted">No role</span>
                        @endforelse
                    </td>

                    <td>
                        @if ($user->is_active)
                            <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                        @else
                            <span class="badge badge-danger" title="This account cannot sign in">
                                <span class="badge-dot"></span> Inactive
                            </span>
                        @endif

                        @unless ($user->is_admin)
                            <div class="text-xs text-muted" style="margin-top:3px">No panel access</div>
                        @endunless
                    </td>

                    <td>
                        {{-- The dot is the glance; the line under it is the detail. --}}
                        <span class="presence {{ $online ? 'is-online' : '' }}">
                            <span class="presence-dot"></span>
                            {{ $online ? 'Online' : 'Offline' }}
                        </span>
                        <div class="text-xs text-muted" style="margin-top:2px">
                            {{ $user->last_seen_at?->diffForHumans() ?? 'Never signed in' }}
                        </div>
                    </td>

                    @allows('settings.sessions.view')
                        <td class="num">
                            @if ($user->active_sessions_count > 0)
                                <a href="{{ route('admin.users.security', $user) }}"
                                   data-modal="{{ route('admin.users.security', $user) }}"
                                   data-modal-title="{{ $user->name }}"
                                   data-modal-sub="Sessions & security"
                                   data-modal-size="lg"
                                   title="{{ $user->active_sessions_count }} active session(s)">
                                    <span class="badge badge-info">{{ $user->active_sessions_count }}</span>
                                </a>
                            @else
                                <span class="text-xs text-muted">0</span>
                            @endif
                        </td>
                    @endallows

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $user->last_login_at?->format('d M Y, H:i') ?? '—' }}
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $user->created_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('settings.sessions.view')
                                <a class="btn btn-icon"
                                   href="{{ route('admin.users.security', $user) }}"
                                   data-modal="{{ route('admin.users.security', $user) }}"
                                   data-modal-title="{{ $user->name }}"
                                   data-modal-sub="Sessions & security"
                                   data-modal-size="lg"
                                   aria-label="View {{ $user->name }}">
                                    <x-icon name="search" :size="15" />
                                </a>
                            @endallows

                            <a class="btn btn-icon" href="{{ route('admin.users.edit', $user) }}"
                               aria-label="Edit {{ $user->name }}">
                                <x-icon name="edit" :size="15" />
                            </a>

                            {{-- Everything destructive lives behind the menu, so
                                 no single mis-click can end a session or an account. --}}
                            <div class="dropdown">
                                <button type="button" class="btn btn-icon" data-dropdown-toggle
                                        aria-expanded="false" aria-label="More actions for {{ $user->name }}">
                                    <x-icon name="more-vertical" :size="15" />
                                </button>

                                <div class="dropdown-menu" style="min-width:216px">
                                    @allows('settings.sessions.view')
                                        <a class="dropdown-item" href="{{ route('admin.users.security', $user) }}"
                                           data-modal="{{ route('admin.users.security', $user) }}"
                                           data-modal-title="{{ $user->name }}"
                                           data-modal-sub="Sessions & security"
                                           data-modal-size="lg">
                                            <x-icon name="shield" :size="15" /> Sessions & security
                                        </a>
                                    @endallows

                                    <a class="dropdown-item" href="{{ route('admin.users.edit', $user) }}">
                                        <x-icon name="edit" :size="15" /> Edit user
                                    </a>

                                    @allows('settings.sessions.edit')
                                        @unless ($isSelf)
                                            <div class="dropdown-divider"></div>

                                            <form method="POST" action="{{ route('admin.users.status', $user) }}"
                                                  data-ajax data-ajax-reload
                                                  onsubmit="if (! confirm('{{ $user->is_active
                                                      ? 'Deactivate '.$user->name.'? They will be signed out of every device and cannot sign in again until reactivated.'
                                                      : 'Reactivate '.$user->name.'? They will be able to sign in again.' }}')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="dropdown-item {{ $user->is_active ? 'is-danger' : '' }}">
                                                    <x-icon :name="$user->is_active ? 'user-x' : 'user-check'" :size="15" />
                                                    {{ $user->is_active ? 'Deactivate account' : 'Activate account' }}
                                                </button>
                                            </form>
                                        @endunless
                                    @endallows

                                    @allows('settings.sessions.delete')
                                        @if ($user->active_sessions_count > 0)
                                            <form method="POST" action="{{ route('admin.users.sessions.destroy-all', $user) }}"
                                                  data-ajax data-ajax-reload
                                                  onsubmit="if (! confirm('Sign {{ $user->name }} out of all {{ $user->active_sessions_count }} device(s)?')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item">
                                                    <x-icon name="logout" :size="15" /> Log out all devices
                                                </button>
                                            </form>
                                        @endif
                                    @endallows

                                    @allows('settings.users.delete')
                                        @unless ($isSelf)
                                            <div class="dropdown-divider"></div>

                                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                                  data-ajax data-ajax-reload
                                                  onsubmit="if (! confirm('Delete {{ $user->name }}? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item is-danger">
                                                    <x-icon name="trash" :size="15" /> Delete user
                                                </button>
                                            </form>
                                        @endunless
                                    @endallows
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No users found</h3>
                            <p class="text-sm">Adjust the filters and try again.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$users" :per-page="$perPage" :page-sizes="$pageSizes" label="users" />
