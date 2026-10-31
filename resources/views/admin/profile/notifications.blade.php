@extends('admin.layouts.app')

@section('title', 'Notification Settings')

@section('content')
    <x-page-header
        title="Notifications"
        subtitle="What you hear about, and how - on this account, on every device you sign in on"
        :crumbs="['Account' => null, 'Profile' => route('admin.profile.edit'), 'Notifications' => null]"
    />

    <form method="POST" action="{{ route('admin.profile.notifications.update') }}" data-ajax data-notify-form>
        @csrf
        @method('PUT')

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">Sound</div>

            <div class="settings-grid">
                <div class="field">
                    <label class="check">
                        <input type="hidden" name="sound" value="0">
                        <input type="checkbox" name="sound" value="1" @checked($prefs['sound']) data-notify-pref="sound">
                        <span>Play a sound when something happens</span>
                    </label>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="hidden" name="voice" value="0">
                        <input type="checkbox" name="voice" value="1" @checked($prefs['voice']) data-notify-pref="voice">
                        <span>Say it out loud - "New order, Table 4"</span>
                    </label>
                </div>

                <div class="field">
                    <label for="np-lang">Voice language</label>
                    <select id="np-lang" name="voice_lang" class="form-control" data-notify-pref="voice_lang">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}" @selected($prefs['voice_lang'] === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="np-volume">Volume</label>
                    <input id="np-volume" type="range" name="volume" min="0" max="100" step="5"
                           value="{{ $prefs['volume'] }}" data-notify-pref="volume" style="width:100%">
                </div>
            </div>

            <div style="margin-top:12px">
                {{-- notify-sound.js plays this with whatever is selected above,
                     saved or not - so the volume can be tried before saving. --}}
                <button type="button" class="btn" data-notify-test>
                    <x-icon name="bell" :size="15" /> Test sound
                </button>
                <span class="text-sm text-muted" style="margin-left:8px">
                    Browsers only play sound after you have clicked somewhere on the page once.
                </span>
            </div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="table-wrap">
                <table class="table table-list">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th style="text-align:center">Show on screen</th>
                            <th style="text-align:center">Sound</th>
                            <th style="text-align:center">Phone / laptop</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($types as $type => $meta)
                            <tr>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:8px">
                                        <x-icon :name="$meta['icon']" :size="16" />
                                        <strong>{{ $meta['label'] }}</strong>
                                    </span>
                                </td>
                                @foreach (['alert', 'sound', 'push'] as $channel)
                                    <td style="text-align:center">
                                        <label class="check" style="justify-content:center">
                                            <input type="checkbox" name="types[{{ $type }}][{{ $channel }}]" value="1"
                                                   @checked($prefs['types'][$type][$channel] ?? true)
                                                   aria-label="{{ $meta['label'] }}: {{ ['alert' => 'show on screen', 'sound' => 'sound', 'push' => 'phone notification'][$channel] }}">
                                        </label>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-sm text-muted">
            Switching something off makes it quiet, not hidden: it is still in the bell's list.
            "Phone / laptop" needs notifications turned on for the device with the ⚡ button beside the bell.
        </p>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
@endsection
