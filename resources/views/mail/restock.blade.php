{{--
    The back-in-stock alert.

    The numbers are the email, not an attachment. Someone who asked to be told
    a perfume was available again is waiting to be called, and the useful version
    of this message is one where each number is already a link they can open from
    their mail client — a wa.me conversation beats a number to retype by hand.

    Same plain-HTML rules as the order alert: mail clients strip styling, and a
    table layout is the only thing that survives Outlook.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.restock.subject', ['name' => $product->name(), 'count' => $entries->count()]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f5f3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2b2b2b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f3;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;overflow:hidden;border:1px solid #e6e2dc;">

                <tr>
                    <td style="padding:24px 28px;background:#1f1b18;color:#f6f5f3;">
                        <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;margin-bottom:6px;">
                            {{ __('mail.restock.product') }}
                        </div>
                        <div style="font-size:22px;font-weight:700;letter-spacing:.01em;">
                            {{ $product->name() }}
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 8px;">
                        <h1 style="margin:0 0 6px;font-size:19px;line-height:1.3;font-weight:700;">
                            {{ __('mail.restock.greeting') }}
                        </h1>
                        <p style="margin:0;font-size:14px;line-height:1.6;color:#5c5750;">
                            {{ __('mail.restock.intro') }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf9f7;border:1px solid #e6e2dc;border-radius:6px;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <div style="font-size:22px;font-weight:700;letter-spacing:.01em;">
                                        {{ $entries->count() }}
                                        {{ __('mail.restock.waiters') }}
                                    </div>
                                    @if ($product->price)
                                        <div style="margin-top:4px;font-size:13px;color:#5c5750;">
                                            {{ __('mail.restock.price') }}:
                                            {{ number_format((float) $product->price, 2, '.', ' ') }} {{ config('chamma.currency.code') }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- One row per person, most recent first. The waiting-since date
                     matters here: somebody who signed up this morning is more
                     likely to still want it than one from last season. --}}
                @foreach ($entries as $entry)
                    <tr>
                        <td style="padding:12px 28px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1px solid #eee9e2;">
                                <tr>
                                    <td style="padding:12px 0;">
                                        <div style="font-size:16px;font-weight:600;letter-spacing:.02em;direction:ltr;text-align:left;">
                                            {{ $entry->phone }}
                                        </div>
                                        <div style="margin-top:2px;font-size:12px;color:#8a837a;">
                                            {{ __('mail.restock.waiting_since') }}
                                            {{ $entry->created_at?->format('d/m/Y') }}
                                        </div>
                                    </td>
                                    <td style="padding:12px 0;text-align:right;" dir="ltr">
                                        <a href="{{ $entry->whatsappUrl() }}"
                                           style="display:inline-block;background:#25d366;color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;padding:9px 14px;border-radius:4px;">
                                            {{ __('mail.restock.call') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td style="padding:24px 28px 8px;">
                        <a href="{{ $storeUrl }}"
                           style="display:inline-block;background:#1f1b18;color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;text-decoration:none;padding:12px 18px;border-radius:4px;">
                            {{ __('mail.restock.view') }}
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px 24px;">
                        <a href="{{ $adminUrl }}"
                           style="font-size:13px;color:#5c5750;">
                            {{ __('mail.restock.open') }}
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px;background:#faf9f7;border-top:1px solid #e6e2dc;font-size:11px;line-height:1.6;color:#8a837a;">
                        {{ __('mail.restock.footer') }}
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
