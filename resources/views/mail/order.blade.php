{{--
    The new order alert.

    Plain HTML, no CSS framework and no build step: the host serves mail from
    the Laravel app, not from the SPA, and a mail client strips most styling
    anyway. Tables and inline styles are what actually survives Outlook and
    Gmail. The table layout is not nostalgia — it is the only layout that does.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.order.subject', ['reference' => $order->reference]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f5f3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2b2b2b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f3;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;overflow:hidden;border:1px solid #e6e2dc;">

                {{-- Reference first: it is the one identifier the owner needs
                     to find this order anywhere else. --}}
                <tr>
                    <td style="padding:24px 28px;background:#1f1b18;color:#f6f5f3;">
                        <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;margin-bottom:6px;">
                            {{ __('mail.order.reference') }}
                        </div>
                        <div style="font-size:26px;font-weight:700;letter-spacing:.02em;">
                            {{ $order->reference }}
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 8px;">
                        <h1 style="margin:0 0 6px;font-size:19px;line-height:1.3;font-weight:700;">
                            {{ __('mail.order.greeting') }}
                        </h1>
                        <p style="margin:0;font-size:14px;line-height:1.6;color:#5c5750;">
                            {{ __('mail.order.intro') }}
                        </p>
                    </td>
                </tr>

                {{-- Phone and total, the two things acted on first. --}}
                <tr>
                    <td style="padding:20px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="padding:12px 14px;background:#faf8f6;border:1px solid #e6e2dc;border-radius:4px;width:50%;vertical-align:top;">
                                    <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8a8378;margin-bottom:4px;">
                                        {{ __('mail.order.phone') }}
                                    </div>
                                    <div style="font-size:16px;font-weight:600;">
                                        <a href="tel:{{ preg_replace('/\s+/', '', $order->phone) }}" style="color:#1f1b18;text-decoration:none;">{{ $order->phone }}</a>
                                    </div>
                                </td>
                                <td width="8"></td>
                                <td style="padding:12px 14px;background:#faf8f6;border:1px solid #e6e2dc;border-radius:4px;width:50%;vertical-align:top;">
                                    <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8a8378;margin-bottom:4px;">
                                        {{ __('mail.order.total') }}
                                    </div>
                                    <div style="font-size:16px;font-weight:700;">
                                        {{ number_format((float) $order->total, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.6;">
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;width:38%;vertical-align:top;">{{ __('mail.order.customer') }}</td>
                                <td style="padding:6px 0;font-weight:600;">{{ $order->customer_name }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;vertical-align:top;">{{ __('mail.order.email') }}</td>
                                <td style="padding:6px 0;">
                                    @if ($customerEmail)
                                        <a href="mailto:{{ $customerEmail }}" style="color:#1f1b18;">{{ $customerEmail }}</a>
                                    @else
                                        <span style="color:#a9a29a;">{{ __('mail.order.email_missing') }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;vertical-align:top;">{{ __('mail.order.city') }}</td>
                                <td style="padding:6px 0;">{{ $order->city }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;vertical-align:top;">{{ __('mail.order.address') }}</td>
                                <td style="padding:6px 0;">{{ $order->address }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;vertical-align:top;">{{ __('mail.order.payment') }}</td>
                                <td style="padding:6px 0;">{{ $order->payment_method }}</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#8a8378;vertical-align:top;">{{ __('mail.order.received') }}</td>
                                <td style="padding:6px 0;">{{ $order->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @if ($order->notes)
                    <tr>
                        <td style="padding:18px 28px 0;">
                            <div style="padding:12px 14px;background:#fffaf0;border-left:3px solid #c9a227;font-size:14px;line-height:1.6;">
                                <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8a8378;margin-bottom:4px;">
                                    {{ __('mail.order.notes') }}
                                </div>
                                {{ $order->notes }}
                            </div>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:24px 28px 0;">
                        <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8a8378;margin-bottom:10px;">
                            {{ __('mail.order.items') }}
                        </div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            <thead>
                                <tr>
                                    <th align="left" style="padding:8px 0;border-bottom:1px solid #e6e2dc;color:#8a8378;font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:600;">
                                        {{ __('mail.order.product') }}
                                    </th>
                                    <th align="right" style="padding:8px 0;border-bottom:1px solid #e6e2dc;color:#8a8378;font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:600;white-space:nowrap;">
                                        {{ __('mail.order.line_total') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #f0ede8;vertical-align:top;">
                                            <div style="font-weight:600;">{{ $item->product_name }}</div>
                                            <div style="font-size:12px;color:#8a8378;margin-top:2px;">
                                                {{ __('mail.order.quantity') }}: {{ $item->quantity }}
                                                &middot;
                                                {{ number_format((float) $item->unit_price, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                                @if ($item->product_sku)
                                                    <br>{{ $item->product_sku }}
                                                @endif
                                            </div>
                                        </td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #f0ede8;font-weight:600;white-space:nowrap;">
                                            {{ number_format((float) $item->subtotal, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            <tr>
                                <td style="padding:5px 0;color:#8a8378;">{{ __('mail.order.subtotal') }}</td>
                                <td align="right" style="padding:5px 0;white-space:nowrap;">
                                    {{ number_format((float) $order->subtotal, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:5px 0;color:#8a8378;">{{ __('mail.order.shipping') }}</td>
                                <td align="right" style="padding:5px 0;white-space:nowrap;">
                                    @if ((float) $order->shipping_cost === 0.0)
                                        <span style="color:#4a7c59;font-weight:600;">{{ __('mail.order.free') }}</span>
                                    @else
                                        {{ number_format((float) $order->shipping_cost, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:12px 0 0;border-top:1px solid #e6e2dc;font-weight:700;font-size:16px;">{{ __('mail.order.total') }}</td>
                                <td align="right" style="padding:12px 0 0;border-top:1px solid #e6e2dc;font-weight:700;font-size:16px;white-space:nowrap;">
                                    {{ number_format((float) $order->total, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 28px;">
                        <a href="{{ $adminUrl }}"
                           style="display:inline-block;padding:13px 22px;background:#1f1b18;color:#f6f5f3;text-decoration:none;border-radius:4px;font-size:14px;font-weight:600;">
                            {{ __('mail.order.open') }}
                        </a>
                        <p style="margin:14px 0 0;font-size:12px;color:#a9a29a;word-break:break-all;">{{ $adminUrl }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px;background:#faf8f6;border-top:1px solid #e6e2dc;font-size:12px;color:#a9a29a;line-height:1.6;">
                        {{ __('mail.order.footer') }}
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>