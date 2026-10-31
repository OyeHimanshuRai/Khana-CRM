@extends('admin.layouts.app')

@section('title', 'Firebase Notifications')

@section('content')
    <x-page-header
        title="Firebase Notifications"
        subtitle="Push new orders, sign-ups and payments to phones and laptops, even with the panel closed"
        :crumbs="['Settings' => null, 'Firebase' => null]"
    />

    <div class="card" style="padding:18px; margin-bottom:14px">
        <div class="form-section-title">Status</div>

        <p class="text-sm" style="margin:0 0 6px">
            @if ($settings->isReady())
                <span class="badge badge-success">On</span> Notifications are being sent through Firebase.
            @elseif ($settings->is_enabled)
                <span class="badge badge-warning">Incomplete</span> Switched on, but some details below are still missing.
            @else
                <span class="badge">Off</span> The bell still updates every few seconds while the panel is open; Firebase adds notifications when it is closed.
            @endif
        </p>

        <p class="text-sm text-muted" style="margin:0">
            {{ $devices }} device(s) registered across all restaurants · {{ $myDevices }} of them yours.
            @if ($settings->last_tested_at)
                Last test {{ $settings->last_tested_at->diffForHumans() }}: {{ $settings->last_test_result }}.
            @endif
        </p>

        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap">
            {{-- Shown by firebase-push.js only while this browser has not been asked yet. --}}
            <button type="button" class="btn" data-push-enable hidden>
                <x-icon name="bell" :size="15" /> Turn on notifications on this device
            </button>

            <form method="POST" action="{{ route('admin.settings.firebase.test') }}" data-ajax>
                @csrf
                <button type="submit" class="btn" @disabled(! $settings->isReady())>Send a test notification</button>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.firebase.update') }}" data-ajax>
        @csrf
        @method('PUT')

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">1. Web app config</div>

            <p class="text-sm text-muted">
                In the <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">Firebase console</a>:
                Project settings → General → Your apps → add a <strong>Web app</strong>, and copy the
                values from its <code>firebaseConfig</code>. These are public - Firebase sends them to every browser.
            </p>

            <div class="settings-grid">
                @foreach ([
                    'project_id' => ['Project ID', 'my-restaurant-app'],
                    'api_key' => ['API key', 'AIza...'],
                    'messaging_sender_id' => ['Messaging sender ID', '123456789012'],
                    'app_id' => ['App ID', '1:123456789012:web:abc123'],
                    'auth_domain' => ['Auth domain (optional)', 'my-restaurant-app.firebaseapp.com'],
                    'storage_bucket' => ['Storage bucket (optional)', ''],
                    'measurement_id' => ['Measurement ID (optional)', 'G-XXXXXXX'],
                ] as $field => [$label, $placeholder])
                    <div class="field">
                        <label for="fb-{{ $field }}">{{ $label }}</label>
                        <input id="fb-{{ $field }}" type="text" name="{{ $field }}" class="form-control"
                               value="{{ $settings->{$field} }}" placeholder="{{ $placeholder }}"
                               autocomplete="off" spellcheck="false" aria-invalid="false">
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">2. Web push certificate (VAPID key)</div>

            <p class="text-sm text-muted">
                Project settings → Cloud Messaging → Web configuration → <strong>Generate key pair</strong>, and copy the key.
            </p>

            <div class="field">
                <label for="fb-vapid">Key pair</label>
                <input id="fb-vapid" type="text" name="vapid_key" class="form-control"
                       value="{{ $settings->vapid_key }}" autocomplete="off" spellcheck="false" aria-invalid="false">
            </div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">3. Service account (to send)</div>

            <p class="text-sm text-muted">
                Project settings → Service accounts → <strong>Generate new private key</strong>. Open the
                downloaded <code>.json</code> file and paste all of it here. It is stored encrypted and never shown again.
            </p>

            @if ($settings->serviceAccountEmail())
                <p class="text-sm" style="margin:0 0 8px">
                    <span class="badge badge-success">Set</span> {{ $settings->serviceAccountEmail() }}
                    <span class="text-muted">- leave the box empty to keep it.</span>
                </p>
            @endif

            <div class="field">
                <label for="fb-sa">Service account JSON</label>
                <textarea id="fb-sa" name="service_account" class="form-control" rows="6"
                          spellcheck="false" autocomplete="off" aria-invalid="false"
                          placeholder='{ "type": "service_account", "project_id": "...", "private_key": "...", "client_email": "..." }'></textarea>
            </div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <label class="check">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" @checked($settings->is_enabled)>
                <span>Send notifications through Firebase</span>
            </label>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
@endsection
