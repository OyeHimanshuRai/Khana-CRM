{{--
    Order confirmation email.

    Plain table-based HTML with inline styles, same reasoning as
    emails/users/welcome.blade.php: mail clients strip <style> blocks and
    ignore flexbox/grid, so nothing from the app's own design system is
    usable here.
--}}
<div style="margin:0;padding:24px 12px;background:#f7f8f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background:#ffffff;border:1px solid #e3e7dd;">

                    <tr>
                        <td style="padding:26px 30px 0;">
                            <div style="font-size:18px;font-weight:700;color:#16241a;">{{ $order->shop?->name }}</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 30px 0;">
                            <h1 style="margin:0 0 6px;font-size:19px;line-height:1.35;color:#16241a;font-weight:650;">
                                Order confirmed
                            </h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#3c4a3f;">
                                Thanks{{ $order->customer ? ', '.$order->customer->name : '' }} — we've received order
                                <strong>{{ $order->order_number }}</strong>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #e3e7dd;font-size:12px;color:#768273;text-transform:uppercase;">Item</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #e3e7dd;font-size:12px;color:#768273;text-transform:uppercase;text-align:right;">Qty</td>
                                    <td style="padding:8px 0;border-bottom:1px solid #e3e7dd;font-size:12px;color:#768273;text-transform:uppercase;text-align:right;">Amount</td>
                                </tr>
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#3c4a3f;">{{ $item->product_name }}</td>
                                        <td style="padding:8px 0;font-size:13px;color:#3c4a3f;text-align:right;">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                                        <td style="padding:8px 0;font-size:13px;color:#3c4a3f;text-align:right;">₹{{ number_format((float) $item->line_total, 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td colspan="2" style="padding:10px 0 0;font-size:14px;font-weight:700;color:#16241a;">Total</td>
                                    <td style="padding:10px 0 0;font-size:14px;font-weight:700;color:#16241a;text-align:right;">₹{{ number_format((float) $order->grand_total, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <p style="margin:0;font-size:13px;line-height:1.7;color:#3c4a3f;">
                                <strong style="color:#16241a;">Payment</strong><br>
                                {{ strtoupper($order->payment_method) }}
                                @if ($order->payment_method === 'cod')
                                    — pay when your order arrives.
                                @else
                                    — we'll confirm once payment is received.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 30px 26px;">
                            <p style="margin:0;font-size:13px;line-height:1.7;color:#3c4a3f;">
                                <strong style="color:#16241a;">Delivering to</strong><br>
                                {{ $order->ship_recipient_name }}<br>
                                {{ $order->shippingAddressLine() }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 30px;border-top:1px solid #e3e7dd;background:#f7f8f5;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#768273;">
                                Order placed {{ $order->placed_at?->format('d M Y, H:i') }}.
                            </p>
                        </td>
                    </tr>
                </table>

                <p style="margin:14px 0 0;font-size:11px;color:#9aa3b2;">
                    &copy; {{ now()->year }} {{ $order->shop?->name }}
                </p>
            </td>
        </tr>
    </table>
</div>
