{{--
    The public site's footer: landing, policy pages, sign-up.

    Everything in it is Settings (App\Support\SiteBrand): the footer logo,
    the tagline, the address, phone, email, WhatsApp, opening hours and
    social links - and the policy pages ticked "show in footer". A field left
    empty in Settings is simply not shown, rather than printed blank.

    @param array       $site         SiteBrand::get()
    @param Collection  $footerPages  Page::footer()
--}}
<footer class="lp-footer">
    <div class="lp-wrap lp-footer-grid">
        <div class="lp-footer-brand">
            @if ($site['footer_logo'])
                <img src="{{ $site['footer_logo'] }}" alt="{{ $site['name'] }}" height="34">
            @else
                <strong>{{ $site['name'] }}</strong>
            @endif

            @if ($site['tagline'])
                <p class="lp-meta">{{ $site['tagline'] }}</p>
            @endif

            @if (! empty($site['social']))
                <nav class="lp-social" aria-label="Social">
                    @foreach ($site['social'] as $label => $url)
                        <a href="{{ $url }}" rel="noopener" target="_blank">{{ $label }}</a>
                    @endforeach
                </nav>
            @endif
        </div>

        <nav class="lp-footer-links" aria-label="Explore">
            <strong class="lp-footer-title">Explore</strong>
            <a href="{{ route('landing') }}">Home</a>
            <a href="{{ route('landing.how') }}">How it works</a>
            <a href="{{ route('landing.features') }}">Features</a>
            <a href="{{ route('landing.billing') }}">Billing</a>
            <a href="{{ route('pricing') }}">Pricing</a>
            <a href="{{ route('landing.faq') }}">FAQ</a>
            <a href="{{ route('demo') }}">Book a demo</a>
        </nav>

        <section class="lp-footer-contact" aria-labelledby="lp-footer-contact-title">
            <strong class="lp-footer-title" id="lp-footer-contact-title">Contact</strong>

            @if ($site['phone'])
                <a href="{{ $site['phone_href'] }}">{{ $site['phone'] }}</a>
            @endif

            @if ($site['whatsapp'])
                <a href="{{ $site['whatsapp_href'] }}" rel="noopener" target="_blank">WhatsApp {{ $site['whatsapp'] }}</a>
            @endif

            @if ($site['email'])
                <a href="mailto:{{ $site['email'] }}">{{ $site['email'] }}</a>
            @endif

            @if ($site['address'])
                <span class="lp-meta">{{ $site['address'] }}</span>
            @endif

            @if ($site['hours'])
                <span class="lp-meta">{{ $site['hours'] }}</span>
            @endif

            @unless ($site['phone'] || $site['email'] || $site['whatsapp'])
                <a href="{{ route('demo') }}">Questions? Book a demo</a>
            @endunless
        </section>

        <nav class="lp-footer-links" aria-label="Company">
            <strong class="lp-footer-title">Company</strong>
            <a href="{{ route('login') }}">Sign in</a>
            <a href="{{ route('register') }}">Start free trial</a>
            @foreach (($footerPages ?? collect()) as $page)
                <a href="{{ route('page.show', $page->slug) }}">{{ $page->title }}</a>
            @endforeach
        </nav>
    </div>

    <div class="lp-wrap">
        <p class="lp-meta lp-footer-copy">&copy; {{ date('Y') }} {{ $site['name'] }}</p>
    </div>
</footer>
