{{--
    The top of a sign-in style page, one look for every door: the wide logo
    (the brand block when none is uploaded) centred over a centred heading,
    and an optional single line beneath.

        <x-auth-head title="Forgot your password?" lede="We will email you a link." />
--}}
@props(['title', 'lede' => null])

@php $siteLogo = \App\Support\CompanySettings::fileUrl('site_logo'); @endphp

@if ($siteLogo)
    <img src="{{ $siteLogo }}" alt="{{ \App\Models\Setting::get('company_name', config('app.name')) }}" class="auth-logo">
@else
    <x-brand sub="" class="auth-logo-brand" />
@endif

<h1 class="auth-title">{{ $title }}</h1>
@if ($lede)
    <p class="auth-lede">{{ $lede }}</p>
@endif
