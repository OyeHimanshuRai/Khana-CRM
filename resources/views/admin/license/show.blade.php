@extends('admin.layouts.app')

@section('title', 'License')

@push('scripts')
    <script src="{{ asset('assets/js/license.js') }}?v={{ filemtime(public_path('assets/js/license.js')) }}" defer></script>
@endpush

@section('content')
    @php
        use App\Support\LicenseAccess;
        $state = $summary['state'];
    @endphp

    <x-page-header
        title="License"
        subtitle="Your license, plan and expiry"
        :crumbs="['Account' => null, 'License' => null]"
    />

    @if ($state === LicenseAccess::REQUIRED || $state === LicenseAccess::REVOKED)
        <div class="card license-activate" style="max-width:560px">
            <div class="card-header"><h3 class="card-title" style="margin:0">Activate your license</h3></div>
            <div style="padding:16px">
                @if ($summary['message'])
                    <p class="text-sm" style="margin-top:0">{{ $summary['message'] }}</p>
                @endif

                @if ($canActivate)
                    <form method="POST" action="{{ route('admin.license.activate') }}" data-ajax data-license-form novalidate>
                        @csrf
                        <div class="field">
                            <label for="license-key">License key</label>
                            <input id="license-key" type="text" name="license_key" class="form-control license-input"
                                   placeholder="TSW-2026-XXXX-XXXX-XXX" autocomplete="off" autocapitalize="characters"
                                   spellcheck="false" maxlength="60" required aria-invalid="false">
                            <div class="form-hint">You only do this once. The key was sent to you by the platform.</div>
                        </div>
                        <div class="modal-actions" style="justify-content:flex-start;margin-top:14px">
                            <button type="submit" class="btn btn-primary">Activate license</button>
                        </div>
                    </form>
                @else
                    <div class="alert alert-info">Only the account owner can register the license. Please ask them to sign in.</div>
                @endif
            </div>
        </div>

    @elseif ($state === LicenseAccess::PENDING)
        <div class="card" style="max-width:560px" data-license-poll="{{ route('admin.license.status') }}">
            <div style="padding:20px;text-align:center">
                <x-icon name="clock" :size="34" />
                <h3 style="margin:10px 0 4px">Waiting for approval</h3>
                <p class="text-sm text-muted" style="margin:0">{{ $summary['message'] }}</p>
                <p class="text-xs text-muted" style="margin:10px 0 0">This page checks automatically - you do not need to refresh.</p>
            </div>
        </div>

    @elseif ($state !== LicenseAccess::OK)
        <div class="alert alert-danger" style="max-width:560px">
            <strong>Access is restricted.</strong> {{ $summary['message'] }}
            <div style="margin-top:8px"><a class="btn btn-sm" href="{{ url('/contact-support') }}">Contact support</a></div>
        </div>
    @endif

    {{-- How to get the key: the customer should never be left wondering who to ask. --}}
    @if ($state !== LicenseAccess::OK)
        <div class="card" style="max-width:560px;margin-top:16px">
            <div class="card-header"><h3 class="card-title" style="margin:0">
                {{ in_array($state, [LicenseAccess::REQUIRED, LicenseAccess::REVOKED], true) ? 'How do I get my license key?' : 'Need help?' }}
            </h3></div>
            <div style="padding:16px">
                @if (in_array($state, [LicenseAccess::REQUIRED, LicenseAccess::REVOKED], true))
                    <ol class="text-sm" style="margin:0 0 12px;padding-left:18px;line-height:1.7">
                        <li>Contact our team - call, WhatsApp or email us.</li>
                        <li>We send you your license key.</li>
                        <li>Enter it above. You only do this once.</li>
                    </ol>
                @endif

                <div class="license-contact">
                    @if ($brand['phone_href'])
                        <a class="btn btn-sm" href="{{ $brand['phone_href'] }}"><x-icon name="phone" :size="15" /> Call {{ $brand['phone'] }}</a>
                    @endif
                    @if ($brand['whatsapp_href'])
                        <a class="btn btn-sm" href="{{ $brand['whatsapp_href'] }}" target="_blank" rel="noopener">WhatsApp</a>
                    @endif
                    @if ($brand['email'])
                        <a class="btn btn-sm" href="mailto:{{ $brand['email'] }}">Email {{ $brand['email'] }}</a>
                    @endif
                    <a class="btn btn-sm" href="{{ url('/contact-support') }}">Contact support</a>
                </div>

                @if ($canActivate)
                    <form method="POST" action="{{ route('admin.license.request-key') }}" data-ajax style="margin-top:12px">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">Request my license key</button>
                        <span class="form-hint" style="display:block;margin-top:6px">We will be told straight away and contact you.</span>
                    </form>
                @endif
            </div>
        </div>
    @endif

    <div style="margin-top:16px">
        @include('admin.license._card')
    </div>
@endsection
