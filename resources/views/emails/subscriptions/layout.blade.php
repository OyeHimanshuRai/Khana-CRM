{{--
    Shared shell for the platform's account emails (sign-up, plan term).

    Plain table HTML with inline styles, as emails/users/welcome.blade.php
    explains: mail clients strip <style>, ignore flexbox and grid, and Outlook
    still renders through Word.

    Sections: heading, intro, details (optional), cta (optional), note (optional).
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
                                @yield('heading')
                            </h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                @yield('intro')
                            </p>
                        </td>
                    </tr>

                    @hasSection('details')
                        <tr>
                            <td style="padding:20px 30px 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                       style="background:#fafbfc;border:1px solid #e6e9ef;">
                                    <tr>
                                        <td style="padding:14px 16px;font-size:13px;line-height:1.7;color:#374151;">
                                            @yield('details')
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    @hasSection('cta')
                        <tr>
                            <td style="padding:22px 30px 0;">
                                {{-- A table cell, not a styled <a>: Outlook ignores
                                     padding on inline elements. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="background:#f97316;">
                                            @yield('cta')
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:22px 30px 26px;">
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#374151;">
                                @hasSection('note')
                                    @yield('note')
                                @else
                                    Sign in any time at
                                    <a href="{{ $loginUrl }}" style="color:#f97316;">{{ $loginUrl }}</a>
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 30px;border-top:1px solid #e6e9ef;background:#fafbfc;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#7b8493;">
                                @if ($supportEmail)
                                    Questions? Write to
                                    <a href="mailto:{{ $supportEmail }}" style="color:#7b8493;">{{ $supportEmail }}</a>.
                                @else
                                    This email is about your {{ $company }} account.
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
