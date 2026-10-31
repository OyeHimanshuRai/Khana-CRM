{{--
    The restaurant's thank-you to its guest. Table HTML, inline styles - see
    emails/users/welcome.blade.php for why.
--}}
<div style="margin:0;padding:24px 12px;background:#f6f7f9;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:520px;background:#ffffff;border:1px solid #e6e9ef;">
                    <tr>
                        <td style="padding:28px 30px 0;">
                            <div style="font-size:18px;font-weight:700;color:#111827;">{{ $shop?->name }}</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 30px 0;">
                            <h1 style="margin:0 0 8px;font-size:19px;line-height:1.35;color:#111827;font-weight:650;">
                                @if ($firstVisit)
                                    Thank you for your first visit, {{ $name }}!
                                @else
                                    Lovely to see you again, {{ $name }}!
                                @endif
                            </h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                @if ($firstVisit)
                                    We hope you enjoyed it, and that we see you again soon.
                                @else
                                    Thank you for coming back - it means a lot to our kitchen.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background:#fafbfc;border:1px solid #e6e9ef;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:13px;line-height:1.7;color:#374151;">
                                        <strong style="color:#111827;">Bill</strong> {{ $invoice->number }}<br>
                                        <strong style="color:#111827;">Date</strong>
                                        {{ $invoice->invoiced_at?->copy()->timezone('Asia/Kolkata')->format('j M Y, g:i A') }}<br>
                                        <strong style="color:#111827;">Total</strong> ₹{{ number_format((float) $invoice->grand_total, 2) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($feedbackUrl)
                        <tr>
                            <td style="padding:20px 30px 0;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="background:#f97316;">
                                            <a href="{{ $feedbackUrl }}"
                                               style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                                                Tell us how it was
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:22px 30px 26px;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#7b8493;">
                                {{ $shop?->name }}@if ($shop?->phone) · {{ $shop->phone }}@endif
                                @if ($shop?->city) · {{ $shop->city }}@endif
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
