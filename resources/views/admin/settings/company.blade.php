@extends('admin.layouts.app')

@section('title', 'Company Settings')

@section('content')
    <x-page-header
        title="Company Settings"
        subtitle="Identity, contact details and integration keys used across the app."
        :crumbs="['Settings' => null, 'General' => null]"
    />

    {{--
        One tab per section, driven by tabs.js. Which tab is open is kept in
        the URL hash, so a section is linkable and survives a reload.

        Without JavaScript the panels are never hidden and the screen reads
        as one long form - see the [data-tabs-ready] rules in theme.css.
    --}}
    <div class="tabs" role="tablist" aria-label="Settings sections" data-tabs="settings">
        @foreach ($sections as $key => $section)
            <button type="button" class="tab" role="tab"
                    id="tab-{{ $key }}"
                    data-tab="{{ $key }}"
                    aria-controls="panel-{{ $key }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                <x-icon :name="$section['icon'] ?? 'settings'" :size="15" />
                <span>{{ $section['label'] }}</span>
            </button>
        @endforeach
    </div>

    <div data-tab-panels="settings">
        @foreach ($sections as $key => $section)
            @php $hasFiles = collect($section['fields'])->contains(fn ($f) => ($f['type'] ?? null) === 'image'); @endphp

            <div class="tab-panel" role="tabpanel" id="panel-{{ $key }}"
                 aria-labelledby="tab-{{ $key }}" data-tab-panel="{{ $key }}">

                {{--
                    One form per section, each data-ajax. Saving is per section
                    on purpose: a validation failure in Mail must not throw away
                    unsaved edits in SEO.

                    Fields, labels, types and rules all come from
                    config/company_settings.php - see App\Support\CompanySettings.
                --}}
                <form method="POST" action="{{ route('admin.settings.company.update', $key) }}"
                      @if ($hasFiles) enctype="multipart/form-data" @endif
                      data-ajax data-settings-section="{{ $key }}">
                    @csrf
                    @method('PUT')

                    <div class="card" style="border-top-width:0">
                        <div class="card-header">
                            <div>
                                <div class="card-title">{{ $section['label'] }}</div>
                                @if (! empty($section['hint']))
                                    <div class="text-xs text-muted">{{ $section['hint'] }}</div>
                                @endif
                            </div>

                            @allows('settings.general.edit')
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            @endallows
                        </div>

                        <div class="card-body">
                            {{--
                                The one thing a payment provider needs from us
                                rather than the other way round, and the one
                                nobody can guess: the URL to point the webhook
                                at. Shown here because this is the screen
                                somebody is on while they have the provider's
                                dashboard open in the next tab.
                            --}}
                            @if ($key === 'payments')
                                <div class="dash-alert is-info" style="margin-bottom:12px">
                                    <x-icon name="globe" :size="17" />
                                    <span class="dash-alert-text">
                                        Webhook URL —
                                        <code>{{ route('payments.webhook') }}</code>
                                        <span class="text-xs text-muted" style="display:block">
                                            Paste this into Razorpay → Settings → Webhooks, tick
                                            <strong>payment.captured</strong>, and put the secret it
                                            gives you in the field below. Without it a guest whose
                                            phone died mid-payment leaves an unsettled table.
                                        </span>
                                    </span>
                                </div>
                            @endif

                            <div class="settings-grid">
                                @foreach ($section['fields'] as $name => $field)
                                    <x-setting-field
                                        :name="$name"
                                        :field="$field"
                                        :value="$values[$name] ?? null"
                                        :file-url="$files[$name] ?? null"
                                        :has-secret="$filledSecrets->contains($name)"
                                        :remove-url="route('admin.settings.company.file.destroy', [$key, $name])"
                                    />
                                @endforeach
                            </div>
                        </div>

                        @allows('settings.general.edit')
                            <div class="card-footer" style="display:flex;justify-content:flex-end">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    Save {{ $section['label'] }}
                                </button>
                            </div>
                        @endallows
                    </div>
                </form>
            </div>
        @endforeach
    </div>
@endsection
