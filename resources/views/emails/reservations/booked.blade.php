{{-- Table booking confirmation; inline styles only, as in emails/orders/placed. --}}
<div style="margin:0;padding:24px 12px;background:#f7f8f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background:#ffffff;border:1px solid #e3e7dd;">
                    <tr>
                        <td style="padding:26px 30px 0;">
                            <div style="font-size:18px;font-weight:700;color:#16241a;">{{ $shop?->name }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 30px 0;">
                            <h1 style="margin:0 0 6px;font-size:19px;line-height:1.35;color:#16241a;font-weight:650;">Your table is booked</h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#3c4a3f;">
                                Thanks, {{ $reservation->guest_name }}. We look forward to seeing you.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 30px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;color:#16241a;">
                                <tr><td style="padding:6px 0;color:#5d6b60;">Date</td><td align="right" style="padding:6px 0;font-weight:600;">{{ $reservation->reserved_for->format('l, j F Y') }}</td></tr>
                                <tr><td style="padding:6px 0;color:#5d6b60;">Time</td><td align="right" style="padding:6px 0;font-weight:600;">{{ $reservation->reserved_for->format('g:i A') }}</td></tr>
                                <tr><td style="padding:6px 0;color:#5d6b60;">Guests</td><td align="right" style="padding:6px 0;font-weight:600;">{{ $reservation->party_size }}</td></tr>
                                @if ((float) $reservation->deposit_amount > 0)
                                    <tr><td style="padding:6px 0;color:#5d6b60;">Advance paid</td><td align="right" style="padding:6px 0;font-weight:600;">₹{{ number_format((float) $reservation->deposit_amount, 2) }}</td></tr>
                                @endif
                                <tr><td style="padding:6px 0;color:#5d6b60;">Booking no.</td><td align="right" style="padding:6px 0;font-weight:600;">#{{ $reservation->id }}</td></tr>
                            </table>
                        </td>
                    </tr>
                    @if (filled($shop?->phone))
                        <tr>
                            <td style="padding:0 30px 26px;font-size:13px;color:#5d6b60;">Need to change something? Call us on {{ $shop->phone }}.</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</div>
