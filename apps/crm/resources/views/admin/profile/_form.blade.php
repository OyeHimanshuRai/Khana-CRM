{{--
    Swappable fragment: the whole profile, minus the page chrome.

    Rendered inside edit.blade.php on a plain visit, and returned on its own
    for the header's modal (see modal.js). Nothing here may depend on the
    surrounding page, and no <script> may live in it - markup injected via
    innerHTML never executes its scripts. The behaviour it needs is
    delegated from app.js and layout.js instead.
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

<form method="POST" action="{{ route('admin.profile.update') }}" data-ajax data-profile-form>
    @csrf
    @method('PUT')

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Your details</div>
                <div class="text-xs text-muted">How your name and email appear across the panel.</div>
            </div>
        </div>

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label for="profile-name">Full name</label>
                    <input id="profile-name" type="text" name="name" required autocomplete="name"
                           value="{{ $user->name }}" aria-invalid="false">
                </div>

                <div class="field">
                    <label for="profile-email">Email address</label>
                    <input id="profile-email" type="email" name="email" required autocomplete="email"
                           value="{{ $user->email }}" aria-invalid="false">
                    <div class="form-hint">This is also the address you sign in with.</div>
                </div>
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </div>
</form>

<form method="POST" action="{{ route('admin.profile.password') }}" data-ajax data-reset-on-success>
    @csrf
    @method('PUT')

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Password</div>
                <div class="text-xs text-muted">
                    Confirm the password you use today before choosing a new one.
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="field" style="max-width:420px">
                <label for="profile-current_password">Current password</label>
                <div class="pw-wrap">
                    <input id="profile-current_password" type="password" name="current_password" required
                           autocomplete="current-password" aria-invalid="false">
                    <button type="button" class="pw-toggle" data-toggle-password
                            aria-controls="profile-current_password" aria-pressed="false">Show</button>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="profile-password">New password</label>
                    <div class="pw-wrap">
                        <input id="profile-password" type="password" name="password" required
                               autocomplete="new-password" aria-invalid="false">
                        <button type="button" class="pw-toggle" data-toggle-password
                                aria-controls="profile-password" aria-pressed="false">Show</button>
                    </div>
                    <div class="form-hint">Minimum 8 characters, and different from the current one.</div>
                </div>

                <div class="field">
                    <label for="profile-password_confirmation">Confirm new password</label>
                    <input id="profile-password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="card-footer" style="display:flex;gap:9px;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Change password</button>
        </div>
    </div>
</form>

{{-- Read-only: roles and access are set in Users, not here. --}}
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Account</div>
            <div class="text-xs text-muted">
                Managed by an administrator from
                @allows('settings.users.view')
                    <a href="{{ route('admin.users.edit', $user) }}">Settings &rarr; Users</a>.
                @else
                    Settings &rarr; Users.
                @endallows
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
