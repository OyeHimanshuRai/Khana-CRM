{{--
    Welcome email.

    Plain table-based HTML with inline styles on purpose: mail clients strip
    <style> blocks, ignore flexbox and grid, and Outlook still renders through
    Word. None of the design system applies here, so nothing from it is used.

    Deliberately carries no password - see App\Mail\WelcomeUserMail.
--}}
<div style="margin:0;padding:24px 12px;background:#f6f7f9;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background:#ffffff;border:1px solid #e6e9ef;">

                    <tr>
                        <td style="padding:26px 30px 0;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $company }}"
                                     style="max-width:180px;max-height:56px;display:block;border:0;">
                            @else
                                <div style="font-size:18px;font-weight:700;color:#111827;">{{ $company }}</div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 30px 0;">
                            <h1 style="margin:0 0 6px;font-size:19px;line-height:1.35;color:#111827;font-weight:650;">
                                Your account is ready
                            </h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                Hello {{ $user->name }}, an administrator has created an account for you
                                on {{ $company }}.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background:#fafbfc;border:1px solid #e6e9ef;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:13px;line-height:1.7;color:#374151;">
                                        <strong style="color:#111827;">Email</strong><br>
                                        {{ $user->email }}

                                        @if ($roles->isNotEmpty())
                                            <br><br>
                                            <strong style="color:#111827;">Role</strong><br>
                                            {{ $roles->implode(', ') }}
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($setPasswordUrl)
                        <tr>
                            <td style="padding:20px 30px 0;">
                                <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#374151;">
                                    Choose your own password to finish setting up. This link works once,
                                    and expires in {{ $expiresInMinutes >= 1440
                                        ? floor($expiresInMinutes / 1440).' day'.(floor($expiresInMinutes / 1440) === 1.0 ? '' : 's')
                                        : $expiresInMinutes.' minutes' }}.
                                </p>

                                {{-- A table cell, not a styled <a>: Outlook ignores
                                     padding on inline elements. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="background:#f97316;">
                                            <a href="{{ $setPasswordUrl }}"
                                               style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                                                Set your password
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#7b8493;">
                                    If the button does not work, copy this into your browser:<br>
                                    <span style="word-break:break-all;color:#374151;">{{ $setPasswordUrl }}</span>
                                </p>
                            </td>
                        </tr>
                    @else
                        {{-- No token could be issued; the admin will have to
                             pass the password on another way. --}}
                        <tr>
                            <td style="padding:20px 30px 0;">
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                    Your administrator will send you your password separately.
                                </p>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:22px 30px 26px;">
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#374151;">
                                Sign in any time at
                                <a href="{{ $loginUrl }}" style="color:#f97316;">{{ $loginUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 30px;border-top:1px solid #e6e9ef;background:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#7b8493;">
                                You are receiving this because an account was created for this address.
                                @if ($supportEmail)
                                    If that was not expected, contact
                                    <a href="mailto:{{ $supportEmail }}" style="color:#7b8493;">{{ $supportEmail }}</a>.
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:14px 0 0;font-size:11px;color:#9aa3b2;">
                    &copy; {{ now()->year }} {{ $company }}
                </p>
            </td>
        </tr>
    </table>
</div>
