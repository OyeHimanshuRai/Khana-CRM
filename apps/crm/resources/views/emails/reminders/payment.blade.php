{{--
    Payment reminder.

    Plain table-based HTML with inline styles, like the rest of the mail in
    this app: clients strip <style> blocks, ignore flexbox and grid, and
    Outlook still renders through Word.

    The tone follows how late the money is - a heads-up before the date, a
    plain statement on it, and a firmer note after. Same facts throughout;
    what changes is only how it opens, because a customer who has done
    nothing wrong should not be written to as though they had.
--}}

@php
    $overdue = $daysOverdue !== null && $daysOverdue > 0;
    $dueToday = $daysOverdue !== null && $daysOverdue === 0;

    $accent = $overdue ? '#b91c1c' : ($dueToday ? '#b45309' : '#1d4ed8');

    $heading = match (true) {
        $overdue => 'Payment overdue',
        $dueToday => 'Payment due today',
        default => 'A payment is coming up',
    };
@endphp

<div style="margin:0;padding:24px 12px;background:#f6f7f9;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px;background:#ffffff;border:1px solid #e6e9ef;">

                    <tr>
                        <td style="padding:26px 30px 0;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $shop?->name }}"
                                     style="max-width:180px;max-height:56px;display:block;border:0;">
                            @else
                                <div style="font-size:18px;font-weight:700;color:#111827;">
                                    {{ $shop?->name }}
                                </div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 30px 0;">
                            <h1 style="margin:0 0 6px;font-size:19px;line-height:1.35;color:{{ $accent }};font-weight:650;">
                                {{ $heading }}
                            </h1>

                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                Dear {{ $customer->name }},

                                @if ($overdue)
                                    our records show a payment of
                                    <strong>₹{{ number_format($amount, 2) }}</strong>
                                    that was due on {{ $dueDate?->format('d M Y') }} —
                                    {{ $daysOverdue }} day{{ $daysOverdue === 1 ? '' : 's' }} ago.
                                @elseif ($dueToday)
                                    a payment of
                                    <strong>₹{{ number_format($amount, 2) }}</strong>
                                    falls due today.
                                @else
                                    this is a reminder that a payment of
                                    <strong>₹{{ number_format($amount, 2) }}</strong>
                                    falls due on {{ $dueDate?->format('d M Y') }}.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background:#fafbfc;border:1px solid #e6e9ef;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:13px;line-height:1.8;color:#374151;">
                                        <strong style="color:#111827;">Invoice</strong><br>
                                        {{ $invoice?->number }}

                                        <br><br>
                                        <strong style="color:#111827;">Invoice date</strong><br>
                                        {{ $invoice?->invoiced_at?->format('d M Y') }}

                                        <br><br>
                                        <strong style="color:#111827;">Due date</strong><br>
                                        {{ $dueDate?->format('d M Y') }}

                                        <br><br>
                                        <strong style="color:#111827;">Amount outstanding</strong><br>
                                        <span style="font-size:17px;font-weight:700;color:{{ $accent }};">
                                            ₹{{ number_format($amount, 2) }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($overdue)
                        <tr>
                            <td style="padding:18px 30px 0;">
                                <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
                                    If you have already paid, please ignore this note — and do tell us,
                                    so we can put our records right.
                                </p>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:20px 30px 26px;">
                            <p style="margin:0 0 4px;font-size:13px;line-height:1.6;color:#374151;">
                                Please contact us to settle, or with any question about this invoice.
                            </p>

                            <p style="margin:0;font-size:13px;line-height:1.7;color:#6b7280;">
                                <strong style="color:#111827;">{{ $shop?->name }}</strong><br>
                                @if ($shop?->addressLine()){{ $shop->addressLine() }}<br>@endif
                                @if ($shop?->phone){{ $shop->phone }}@endif
                                @if ($shop?->email) · {{ $shop->email }}@endif
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 30px 24px;">
                            <div style="border-top:1px solid #e6e9ef;padding-top:14px;font-size:11.5px;line-height:1.6;color:#9ca3af;">
                                This is an automated reminder about invoice {{ $invoice?->number }}.
                                Amounts shown were correct when it was sent.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</div>
