{{--
    Password reset email.

    Same table-based, inline-styled HTML as the welcome email and for the same
    reasons: mail clients strip <style> blocks, ignore flexbox and grid, and
    Outlook still renders through Word. None of the design system applies here,
    so nothing from it is used.

    Carries a one-time link and no password — see App\Mail\PasswordResetMail.
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
                                Reset your password
                            </h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                Hello {{ $user->name }}, somebody asked to reset the password for
                                {{ $user->email }} on {{ $company }}.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#374151;">
                                This link works once and expires in
                                {{ $expiresInMinutes >= 60
                                    ? floor($expiresInMinutes / 60).' hour'.(floor($expiresInMinutes / 60) === 1.0 ? '' : 's')
                                    : $expiresInMinutes.' minutes' }}.
                            </p>

                            {{-- A table cell, not a styled <a>: Outlook ignores
                                 padding on inline elements. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background:#f97316;">
                                        <a href="{{ $resetUrl }}"
                                           style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                                            Choose a new password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#7b8493;">
                                If the button does not work, copy this into your browser:<br>
                                <span style="word-break:break-all;color:#374151;">{{ $resetUrl }}</span>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 30px 26px;">
                            {{--
                                The sentence that matters when this email was
                                not asked for: nothing has changed yet, and the
                                old password still works. Telling somebody to
                                "secure their account" would send them looking
                                for a problem that does not exist.
                            --}}
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#374151;">
                                If you did not ask for this, you can ignore this email — your password
                                has not been changed. Sign in any time at
                                <a href="{{ $loginUrl }}" style="color:#f97316;">{{ $loginUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 30px;border-top:1px solid #e6e9ef;background:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#7b8493;">
                                You are receiving this because a password reset was requested for this
                                address.
                                @if ($supportEmail)
                                    Questions? Write to
                                    <a href="mailto:{{ $supportEmail }}" style="color:#7b8493;">{{ $supportEmail }}</a>.
                                @endif
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</div>
