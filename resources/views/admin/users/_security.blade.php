{{--
    Swappable fragment: one user's whole security picture.

    Rendered inside security.blade.php on a plain visit, and returned on its
    own for the list's modal. No <script> may live here - markup injected via
    innerHTML never runs its scripts, so the behaviour is delegated from
    tabs.js, modal.js and app.js instead.
--}}

@php
    $canManage = auth()->user()->can('settings.sessions.delete');
    $activeBlocks = $blocks->filter->isEnforced();
@endphp

<div class="sec-summary">
    <div class="sec-identity">
        <x-avatar :user="$user" class="avatar-xl" />
        <div>
            <div class="sec-name">{{ $user->name }}</div>
            <div class="text-sm text-muted">{{ $user->email }}</div>
            @if ($user->mobile)
                <div class="text-sm text-muted">{{ $user->mobile }}</div>
            @endif

            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                @forelse ($user->roles as $role)
                    <span class="badge {{ $role->name === \App\Models\User::SUPER_ADMIN ? 'badge-brand' : 'badge-info' }}">
                        {{ $role->name }}
                    </span>
                @empty
                    <span class="badge">No role</span>
                @endforelse

                <span class="badge {{ $user->is_active ? 'badge-success' : 'badge-danger' }}">
                    <span class="badge-dot"></span> {{ $user->is_active ? 'Active' : 'Deactivated' }}
                </span>

                <span class="presence {{ $user->isOnline() ? 'is-online' : '' }}">
                    <span class="presence-dot"></span> {{ $user->presence() }}
                </span>
            </div>
        </div>
    </div>

    <dl class="sec-facts">
        <div><dt>Active sessions</dt><dd>{{ $activeCount }}</dd></div>
        <div><dt>Total logins</dt><dd>{{ number_format($user->login_count) }}</dd></div>
        <div><dt>Failed attempts</dt><dd>{{ number_format($failedCount) }}</dd></div>
        <div><dt>Blocked IPs</dt><dd>{{ $activeBlocks->count() }}</dd></div>
        <div><dt>Last login</dt><dd>{{ $user->last_login_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
        <div><dt>Member since</dt><dd>{{ $user->created_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>
</div>

@if ($canManage && $activeCount > 0)
    <div class="sec-toolbar">
        {{-- Cancelling has to stop the event as well as prevent it: app.js
             listens on document, so a plain `return false` suppresses the
             normal submit and posts the form over AJAX anyway. --}}
        <form method="POST" action="{{ route('admin.users.sessions.destroy-all', $user) }}"
              data-ajax data-ajax-reload
              onsubmit="if (! confirm('Sign {{ $user->name }} out of every device?')) { event.stopPropagation(); return false; }">
            @csrf
            @method('DELETE')
            {{-- Spares the admin's own session when they are looking at
                 their own account; a no-op for anyone else's. --}}
            <input type="hidden" name="keep_current" value="1">
            <button type="submit" class="btn btn-sm btn-danger">
                <x-icon name="logout" :size="14" /> Log out all devices
            </button>
        </form>
    </div>
@endif

<div class="tabs" role="tablist" aria-label="Security sections" data-tabs="user-security">
    <button type="button" class="tab" role="tab" data-tab="sessions" aria-selected="true">
        <x-icon name="panel-left" :size="15" /> <span>Sessions ({{ $activeCount }})</span>
    </button>
    <button type="button" class="tab" role="tab" data-tab="history" aria-selected="false">
        <x-icon name="list" :size="15" /> <span>Login history</span>
    </button>
    <button type="button" class="tab" role="tab" data-tab="ips" aria-selected="false">
        <x-icon name="shield" :size="15" /> <span>IP history</span>
    </button>
    <button type="button" class="tab" role="tab" data-tab="blocks" aria-selected="false">
        <x-icon name="user-x" :size="15" /> <span>Blocked IPs ({{ $activeBlocks->count() }})</span>
    </button>
</div>

<div data-tab-panels="user-security">

    {{-- ------------------------------------------------------- sessions --}}
    <div class="tab-panel" role="tabpanel" data-tab-panel="sessions">
        <div class="card" style="border-top-width:0">
            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>IP</th>
                            <th>Signed in</th>
                            <th>Last active</th>
                            <th>Status</th>
                            @if ($canManage)
                                <th class="col-action">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            @php $isCurrent = $session->isCurrent($currentSessionId); @endphp
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:9px">
                                        <x-icon :name="match ($session->device_type) {
                                            'mobile' => 'user',
                                            'tablet' => 'file',
                                            'bot' => 'settings',
                                            default => 'panel-left',
                                        }" :size="16" class="text-muted" />
                                        <span>
                                            <strong style="display:block;color:var(--heading)">
                                                {{ $session->device_name ?? 'Unknown device' }}
                                                @if ($isCurrent)
                                                    <span class="badge badge-brand" style="margin-left:4px">This device</span>
                                                @endif
                                            </strong>
                                            <span class="text-xs text-muted">{{ $session->describeDevice() }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-sm">
                                    <span class="list-ref">{{ $session->ip_address ?? '—' }}</span>
                                    @php $place = $locations[$session->ip_address] ?? $session->location; @endphp
                                    @if ($place)
                                        <div class="text-xs text-muted" style="margin-top:2px">{{ $place }}</div>
                                    @endif
                                </td>
                                <td class="text-muted text-sm" style="white-space:nowrap">
                                    {{ $session->login_at?->format('d M Y, H:i') ?? '—' }}
                                </td>
                                <td class="text-muted text-sm" style="white-space:nowrap">
                                    {{ $session->last_activity_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td>
                                    @if ($session->isOnline())
                                        <span class="badge badge-success"><span class="badge-dot"></span> Online</span>
                                    @elseif ($session->isActive())
                                        <span class="badge badge-warning"><span class="badge-dot"></span> Idle</span>
                                    @else
                                        <span class="badge">{{ $session->status() }}</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="col-action">
                                        @if ($session->isActive() && ! $isCurrent)
                                            <form method="POST"
                                                  action="{{ route('admin.users.sessions.destroy', [$user, $session]) }}"
                                                  data-ajax data-ajax-reload
                                                  onsubmit="if (! confirm('Sign out {{ $session->describeDevice() }}?')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm">Log out</button>
                                            </form>
                                        @elseif ($isCurrent)
                                            <span class="text-xs text-muted">In use</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty">
                                        <x-icon name="inbox" :size="26" />
                                        <h3>No sessions recorded</h3>
                                        <p class="text-sm">Sessions appear here after the next sign-in.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- -------------------------------------------------- login history --}}
    <div class="tab-panel" role="tabpanel" data-tab-panel="history">
        <div class="card" style="border-top-width:0">
            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>Date &amp; time</th>
                            <th>IP &amp; location</th>
                            <th>Device</th>
                            <th>Browser</th>
                            <th>OS</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $entry)
                            <tr>
                                <td class="text-muted text-sm" style="white-space:nowrap">
                                    {{ $entry->created_at->format('d M Y, H:i') }}
                                </td>
                                <td class="text-sm">
                                    <span class="list-ref">{{ $entry->ip_address ?? '—' }}</span>
                                    @if ($locations[$entry->ip_address] ?? null)
                                        <div class="text-xs text-muted" style="margin-top:2px">
                                            {{ $locations[$entry->ip_address] }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-sm text-muted">{{ Str::title($entry->device_type ?? '—') }}</td>
                                <td class="text-sm text-muted">
                                    {{ trim(($entry->browser ?? '—').' '.($entry->browser_version ?? '')) }}
                                </td>
                                <td class="text-sm text-muted">
                                    {{ trim(($entry->operating_system ?? '—').' '.($entry->os_version ?? '')) }}
                                </td>
                                <td>
                                    @if ($entry->isSuccess())
                                        <span class="badge badge-success">Success</span>
                                    @else
                                        <span class="badge badge-danger" title="{{ $entry->describeReason() }}">
                                            {{ $entry->status === \App\Models\LoginHistory::BLOCKED ? 'Blocked' : 'Failed' }}
                                        </span>
                                        <div class="text-xs text-muted" style="margin-top:2px">
                                            {{ $entry->describeReason() }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty">
                                        <x-icon name="inbox" :size="26" />
                                        <h3>No login attempts recorded</h3>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------- ip history --}}
    <div class="tab-panel" role="tabpanel" data-tab-panel="ips">
        <div class="card" style="border-top-width:0">
            @if ($canManage)
                {{--
                    Blocking takes a list, so several addresses from the table
                    below can be dealt with in one go.
                --}}
                <div class="card-body" style="border-bottom:1px solid var(--border)">
                    <form method="POST" action="{{ route('admin.users.ips.block', $user) }}"
                          data-ajax data-ajax-reload>
                        @csrf

                        <div class="settings-grid">
                            <div class="field field-full">
                                <label for="block-ips">IP addresses to block</label>
                                <textarea id="block-ips" name="ip_addresses" class="form-control"
                                          placeholder="203.0.113.5, 198.51.100.22" style="min-height:64px"
                                          aria-invalid="false" data-ip-target></textarea>
                                <div class="form-hint">
                                    Separate several with a comma, a space or a new line.
                                    Use "Block" in the table below to add one.
                                </div>
                            </div>

                            <div class="field">
                                <label for="block-scope">Applies to</label>
                                <select id="block-scope" name="scope" class="form-control">
                                    <option value="user">This account only</option>
                                    <option value="global">Every account</option>
                                </select>
                            </div>

                            <div class="field">
                                <label for="block-duration">Duration</label>
                                <select id="block-duration" name="duration" class="form-control">
                                    <option value="permanent">Permanent</option>
                                    <option value="1h">1 hour</option>
                                    <option value="24h">24 hours</option>
                                    <option value="7d">7 days</option>
                                    <option value="30d">30 days</option>
                                </select>
                            </div>

                            <div class="field field-full">
                                <label for="block-reason">Reason</label>
                                <input id="block-reason" type="text" name="reason" class="form-control"
                                       placeholder="Why is this address being blocked?" aria-invalid="false">
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-sm btn-danger">
                                <x-icon name="shield" :size="14" /> Block addresses
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>IP address</th>
                            <th>Location</th>
                            <th class="num">Attempts</th>
                            <th class="num">Successful</th>
                            <th>Last used</th>
                            <th>State</th>
                            @if ($canManage)
                                <th class="col-action">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ipHistory as $row)
                            @php $blocked = $activeBlocks->firstWhere('ip_address', $row->ip_address); @endphp
                            <tr>
                                <td><span class="list-ref">{{ $row->ip_address }}</span></td>
                                <td class="text-sm text-muted">
                                    {{ $locations[$row->ip_address] ?? 'Unknown' }}
                                </td>
                                <td class="num text-muted">{{ $row->attempts }}</td>
                                <td class="num text-muted">{{ $row->successes }}</td>
                                <td class="text-muted text-sm" style="white-space:nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($row->last_used_at)->format('d M Y, H:i') }}
                                </td>
                                <td>
                                    @if ($blocked)
                                        <span class="badge badge-danger">Blocked</span>
                                    @else
                                        <span class="badge badge-success">Allowed</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="col-action">
                                        @unless ($blocked)
                                            {{-- Fills the textarea above rather than blocking
                                                 outright, so scope and reason are a decision. --}}
                                            <button type="button" class="btn btn-sm"
                                                    data-ip-add="{{ $row->ip_address }}">
                                                Block
                                            </button>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty">
                                        <x-icon name="inbox" :size="26" />
                                        <h3>No addresses recorded</h3>
                                        <p class="text-sm">Addresses appear here after the next sign-in attempt.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------- blocked ips --}}
    <div class="tab-panel" role="tabpanel" data-tab-panel="blocks">
        <div class="card" style="border-top-width:0">
            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>IP address</th>
                            <th>Scope</th>
                            <th>Reason</th>
                            <th>Blocked by</th>
                            <th>Expires</th>
                            <th>State</th>
                            @if ($canManage)
                                <th class="col-action">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($blocks as $block)
                            <tr>
                                <td><span class="list-ref">{{ $block->ip_address }}</span></td>
                                <td class="text-sm text-muted">{{ $block->describeScope() }}</td>
                                <td class="text-sm text-muted">{{ $block->reason ?: '—' }}</td>
                                <td class="text-sm text-muted" style="white-space:nowrap">
                                    {{ $block->blocked_by_name ?? 'System' }}
                                    <div class="text-xs">{{ $block->blocked_at?->format('d M Y, H:i') }}</div>
                                </td>
                                <td class="text-sm text-muted" style="white-space:nowrap">
                                    {{ $block->describeDuration() }}
                                </td>
                                <td>
                                    @if ($block->isEnforced())
                                        <span class="badge badge-danger"><span class="badge-dot"></span> Enforced</span>
                                    @else
                                        <span class="badge">{{ $block->status === \App\Models\BlockedIp::LIFTED ? 'Lifted' : 'Expired' }}</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="col-action">
                                        @if ($block->isEnforced())
                                            <form method="POST"
                                                  action="{{ route('admin.users.ips.unblock', [$user, $block]) }}"
                                                  data-ajax data-ajax-reload
                                                  onsubmit="if (! confirm('Unblock {{ $block->ip_address }}?')) { event.stopPropagation(); return false; }">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm">Unblock</button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty">
                                        <x-icon name="shield" :size="26" />
                                        <h3>No blocked addresses</h3>
                                        <p class="text-sm">Block one from the IP history tab.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
