                <p class="lp-demo-thanks" role="status" data-demo-thanks
                   @unless (session('demo_status')) hidden @endunless>{{ session('demo_status') }}</p>

                @if ($errors->any())
                    <ul class="lp-demo-errors" role="alert">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('demo-request.store') }}"
                      data-ajax data-demo-form data-reset-on-success novalidate>
                    @csrf

                    {{--
                        The honeypot. Hidden from people, irresistible to bots,
                        and free — unlike a CAPTCHA, which would cost this form
                        real enquiries to stop a problem somebody reads past in
                        an inbox anyway.
                    --}}
                    <div class="lp-hp" aria-hidden="true">
                        <label for="{{ $idp }}-website">Website</label>
                        <input id="{{ $idp }}-website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="lp-demo-grid">
                        <p class="lp-field lp-field-full field">
                            <label for="{{ $idp }}-name" class="lp-sr">Your name</label>
                            <input id="{{ $idp }}-name" type="text" name="name" required maxlength="120" placeholder="Your name *"
                                   value="{{ old('name') }}" autocomplete="name">
                        </p>

                        <p class="lp-field field">
                            <label for="{{ $idp }}-phone" class="lp-sr">Phone</label>
                            <input id="{{ $idp }}-phone" type="tel" name="phone" maxlength="30" placeholder="Phone"
                                   value="{{ old('phone') }}" autocomplete="tel">
                        </p>

                        <p class="lp-field field">
                            <label for="{{ $idp }}-email" class="lp-sr">Email</label>
                            <input id="{{ $idp }}-email" type="email" name="email" maxlength="150" placeholder="Email"
                                   value="{{ old('email') }}" autocomplete="email">
                        </p>

                        <div class="lp-field lp-field-full field demo-otp" data-otp
                             data-send-url="{{ route('demo.otp.send') }}" data-verify-url="{{ route('demo.otp.verify') }}">
                            <button type="button" class="lp-btn lp-btn-ghost demo-otp-send" data-otp-send hidden>Send OTP to this email</button>

                            <div class="demo-otp-panel" data-otp-panel hidden>
                                <label for="{{ $idp }}-otp" class="lp-sr">6-digit code</label>
                                <div class="demo-otp-row">
                                    <input id="{{ $idp }}-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                           pattern="[0-9]{6}" placeholder="Enter 6-digit OTP" data-otp-input>
                                    <button type="button" class="lp-btn lp-btn-solid" data-otp-verify>Verify</button>
                                </div>
                                <button type="button" class="demo-otp-resend" data-otp-resend disabled>Resend OTP</button>
                            </div>

                            <p class="demo-otp-msg" role="status" data-otp-msg hidden></p>
                        </div>

                        <p class="lp-field field">
                            <label for="{{ $idp }}-business" class="lp-sr">Restaurant name</label>
                            <input id="{{ $idp }}-business" type="text" name="business_name" maxlength="150" placeholder="Restaurant name"
                                   value="{{ old('business_name') }}" autocomplete="organization">
                        </p>

                        <p class="lp-field field">
                            <label for="{{ $idp }}-city" class="lp-sr">City</label>
                            <input id="{{ $idp }}-city" type="text" name="city" maxlength="90" placeholder="City"
                                   value="{{ old('city') }}" autocomplete="address-level2">
                        </p>

                        <p class="lp-field lp-field-full field">
                            <label for="{{ $idp }}-outlets" class="lp-sr">How many outlets</label>
                            <input id="{{ $idp }}-outlets" type="number" name="outlets" min="1" max="65535"
                                   value="{{ old('outlets') }}" placeholder="How many outlets (e.g. 1)">
                        </p>

                        <p class="lp-field lp-field-full field">
                            <label for="{{ $idp }}-message" class="lp-sr">Anything else</label>
                            <textarea id="{{ $idp }}-message" name="message" rows="2" maxlength="2000" placeholder="Anything else">{{ old('message') }}</textarea>
                        </p>
                    </div>

                    {{-- Either will do — insisting on an email loses the owner
                         who only has a phone, and that is the likelier buyer. --}}
                    <p class="lp-demo-note">Phone or email, whichever you prefer. An email is confirmed with a 6-digit code we send to it.</p>

                    <button type="submit" class="lp-btn lp-btn-solid">Book a demo</button>
                </form>
            
