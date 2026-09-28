{{--
    Swappable fragment: the whole profile, minus the page chrome.

    Rendered inside edit.blade.php on a plain visit, and returned on its own
    for the header's modal (see modal.js). Nothing here may depend on the
    surrounding page, and no <script> may live in it - markup injected via
    innerHTML never executes its scripts. The behaviour it needs is
    delegated from app.js and layout.js instead.

    Only the picture and the devices are CRM's to change. Name, email and
    password are Khana Accounts' and are shown here, not edited - see
    ProfileController.
--}}

{{--
    Every form here carries data-ajax, so app.js posts them with fetch() and
    drives the toasts + inline field errors from the JSON envelope. They stay
    ordinary POST forms without JavaScript.
--}}
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Profile picture</div>
            <div class="text-xs text-muted">JPG, PNG or WebP, up to 2&nbsp;MB.</div>
        </div>
    </div>

    <div class="card-body">
        <div class="avatar-upload">
            {{-- data-avatar so the picker can preview into it before upload. --}}
            <x-avatar :user="$user" class="avatar-xl" live />

            <div class="avatar-upload-actions">
                {{-- enctype matters for the no-JS path; fetch() infers it from
                     the FormData either way. --}}
                <form method="POST" action="{{ route('admin.profile.avatar') }}"
                      enctype="multipart/form-data" data-ajax data-profile-form>
                    @csrf

                    <div class="field" style="margin-bottom:10px">
                        <label for="profile-avatar" class="sr-only">Choose a picture</label>
                        <input id="profile-avatar" type="file" name="avatar"
                               accept="image/jpeg,image/png,image/webp" data-avatar-input required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="download" :size="14" style="transform:rotate(180deg)" />
                        Upload picture
                    </button>
                </form>

                {{-- Always rendered, shown only while a picture exists: the
                     fragment is not re-fetched after an upload, so layout.js
                     toggles this rather than the server. --}}
                <form method="POST" action="{{ route('admin.profile.avatar.destroy') }}"
                      data-ajax data-profile-form data-avatar-remove
                      @unless ($user->avatarUrl()) hidden @endunless
                      onsubmit="return confirm('Remove your profile picture?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm">
                        <x-icon name="trash" :size="14" /> Remove
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Read-only: Accounts holds these, and copies them down at each sign-in. --}}
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Your details</div>
            <div class="text-xs text-muted">
                Your name, email and password live in your Khana account, and are
                the same in every Khana product you use.
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="form-row" style="margin-bottom:0">
            <div class="field" style="margin-bottom:0">
                <div class="form-label">Full name</div>
                <div class="text-sm">{{ $user->name }}</div>
            </div>

            <div class="field" style="margin-bottom:0">
                <div class="form-label">Email address</div>
                <div class="text-sm">{{ $user->email }}</div>
            </div>
        </div>
    </div>

    @if ($accountsProfile)
        <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
            <a class="btn" href="{{ $accountsProfile }}">
                Change name, email or password in Khana Accounts
            </a>
        </div>
    @endif
</div>

{{--
    Where this person is signed in to CRM.

    Their own sessions only, so a till left signed in at the other branch or
    a lost phone can be dealt with without asking anybody. The device in use
    is marked and cannot be ended here - that is what Sign out is for.
--}}
@php
    $openSessions = $sessions->filter->isActive();
    $others = $openSessions->reject(fn ($session) => $session->isCurrent($currentSessionId));
@endphp

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Signed-in devices</div>
            <div class="text-xs text-muted">
                Where you are signed in to CRM. Sign out anything you do not recognise.
            </div>
        </div>

        @if ($others->isNotEmpty())
            {{-- Cancelling has to stop the event as well as prevent it:
                 app.js listens on document, so a plain `return false`
                 would still post the form over AJAX. --}}
            <form method="POST" action="{{ route('admin.profile.sessions.destroy-others') }}"
                  data-ajax data-ajax-reload
                  onsubmit="if (! confirm('Sign out every other device?')) { event.stopPropagation(); return false; }">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-danger">
                    <x-icon name="logout" :size="14" /> Sign out other devices
                </button>
            </form>
        @endif
    </div>

    <div class="table-wrap">
        <table class="table table-list">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>IP</th>
                    <th>Last active</th>
                    <th>Status</th>
                    <th class="col-action">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    @php $isCurrent = $session->isCurrent($currentSessionId); @endphp
                    <tr>
                        <td>
                            <strong style="display:block;color:var(--heading)">
                                {{ $session->device_name ?? 'Unknown device' }}
                                @if ($isCurrent)
                                    <span class="badge badge-brand" style="margin-left:4px">This device</span>
                                @endif
                            </strong>
                            <span class="text-xs text-muted">{{ $session->describeDevice() }}</span>
                        </td>
                        <td class="text-sm">
                            <span class="list-ref">{{ $session->ip_address ?? '—' }}</span>
                            @php $place = $locations[$session->ip_address] ?? $session->location; @endphp
                            @if ($place)
                                <div class="text-xs text-muted" style="margin-top:2px">{{ $place }}</div>
                            @endif
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
                        <td class="col-action">
                            @if ($isCurrent)
                                <span class="text-xs text-muted">In use</span>
                            @elseif ($session->isActive())
                                <form method="POST"
                                      action="{{ route('admin.profile.sessions.destroy', $session) }}"
                                      data-ajax data-ajax-reload
                                      onsubmit="if (! confirm('Sign out {{ $session->describeDevice() }}?')) { event.stopPropagation(); return false; }">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm">Sign out</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty">
                                <x-icon name="inbox" :size="26" />
                                <h3>No devices recorded</h3>
                                <p class="text-sm">They appear here after the next sign-in.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Read-only: which role someone holds is set in Accounts; what it may do is set here. --}}
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Account</div>
            <div class="text-xs text-muted">
                Your role is given to you by the owner in Khana Accounts.
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="form-row">
            <div class="field">
                <div class="form-label">Roles</div>
                <div style="display:flex;flex-wrap:wrap;gap:7px">
                    @forelse ($roleNames as $roleName)
                        <span class="badge {{ $roleName === \App\Models\User::SUPER_ADMIN ? 'badge-brand' : 'badge-info' }}">
                            {{ $roleName }}
                        </span>
                    @empty
                        <span class="text-xs text-muted">No role assigned</span>
                    @endforelse
                </div>
            </div>

            <div class="field">
                <div class="form-label">Permissions</div>
                <div class="text-sm text-muted">
                    {{ $inheritedCount }} inherited from
                    {{ $roleNames->count() }} {{ Str::plural('role', $roleNames->count()) }}
                    &middot; {{ $directCount }} granted directly
                </div>
            </div>
        </div>

        <div class="form-row" style="margin-bottom:0">
            <div class="field" style="margin-bottom:0">
                <div class="form-label">Member since</div>
                <div class="text-sm text-muted">
                    {{ $user->created_at?->format('d M Y') ?? '—' }}
                </div>
            </div>

            <div class="field" style="margin-bottom:0">
                <div class="form-label">User ID</div>
                <div class="text-sm text-muted">
                    USR-{{ str_pad($user->id, 4, '0', STR_PAD_LEFT) }}
                </div>
            </div>
        </div>
    </div>
</div>
