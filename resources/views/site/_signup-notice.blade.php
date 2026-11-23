{{--
    The thank-you after signing up, on the home page.

    Nobody is signed in by signing up: the address was confirmed with a one-time code
    before the form could be sent, so the account is ready and the visitor signs in.
    Stays until it is closed (a toast is gone before anyone has read two lines).
--}}
@php($notice = session('signup_notice'))
@if (is_array($notice))
    <section class="lp-notice" role="status" data-signup-notice>
        <div class="lp-wrap lp-notice-in">
            <div class="lp-notice-icon" aria-hidden="true">✓</div>
            <div class="lp-notice-body">
                <strong>Thank you for registering!</strong>
                <p>Your email <b>{{ $notice['email'] }}</b> is verified and your account is ready. <a href="{{ route('login') }}">Sign in</a> to get started.</p>
                @if ($notice['sent'])
                    <p class="lp-notice-small">We have also sent you a welcome email.</p>
                @endif
            </div>
            <button type="button" class="lp-notice-close" aria-label="Close this message" data-signup-notice-close>×</button>
        </div>
    </section>
    <script>
        document.querySelector('[data-signup-notice-close]').addEventListener('click', function () {
            document.querySelector('[data-signup-notice]').remove();
        });
    </script>
@endif
