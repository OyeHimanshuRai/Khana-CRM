{{--
    The thank-you after signing up, on the home page.

    Nobody is signed in by signing up: the account opens when the address on it
    has been confirmed from the link in the email. So this says exactly that, stays
    until it is closed (a toast is gone before anyone has read two lines), and
    points at the sign-in page for "send the link again".
--}}
@php($notice = session('signup_notice'))
@if (is_array($notice))
    <section class="lp-notice" role="status" data-signup-notice>
        <div class="lp-wrap lp-notice-in">
            <div class="lp-notice-icon" aria-hidden="true">✓</div>
            <div class="lp-notice-body">
                <strong>Thank you for registering!</strong>
                @if ($notice['sent'])
                    <p>We have sent a verification link to <b>{{ $notice['email'] }}</b>. Open it to verify your email and activate your account, then <a href="{{ route('login') }}">sign in</a>.</p>
                    <p class="lp-notice-small">Not in your inbox? Check the spam folder. You cannot sign in until the email is verified.</p>
                @else
                    <p>Your account is created, but we could not send the verification email to <b>{{ $notice['email'] }}</b> just now.</p>
                    <p class="lp-notice-small"><a href="{{ route('login') }}">Sign in</a> and choose "Send the verification link again". You cannot sign in until the email is verified.</p>
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
