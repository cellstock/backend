<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment confirmation</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 20px;background:linear-gradient(90deg,#2563eb,#3b82f6);">
                            <p style="margin:0;font-size:13px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#dbeafe;">
                                {{ $appName }}
                            </p>
                            <h1 style="margin:8px 0 0;font-size:22px;font-weight:700;color:#ffffff;">
                                Your order has been dispatched
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
                                Good news your order is on its way. You can track delivery using the details below.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
                                        <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">
                                            Order number
                                        </p>
                                        <p style="margin:0;font-size:18px;font-weight:700;color:#1d4ed8;">
                                            #{{ $orderNumber }}
                                        </p>
                                    </td>
                                </tr>
                                @if (! empty($trackingNumber))
                                    <tr>
                                        <td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
                                            <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">
                                                Tracking number
                                            </p>
                                            <p style="margin:0;font-size:15px;font-weight:600;color:#0f172a;word-break:break-all;">
                                                {{ $trackingNumber }}
                                            </p>
                                        </td>
                                    </tr>
                                @endif
                                @if (! empty($carrier))
                                    <tr>
                                        <td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
                                            <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">
                                                Carrier
                                            </p>
                                            <p style="margin:0;font-size:15px;font-weight:600;text-transform:uppercase;color:#0f172a;">
                                                {{ $carrier }}
                                            </p>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <p style="margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">
                                            Tracking link
                                        </p>
                                        @if ($trackingUrl)
                                            <p style="margin:0 0 14px;font-size:13px;line-height:1.5;color:#475569;word-break:break-all;">
                                                <a href="{{ $trackingUrl }}" style="color:#2563eb;text-decoration:underline;">{{ $trackingUrl }}</a>
                                            </p>
                                            <a href="{{ $trackingUrl }}"
                                               style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 20px;border-radius:10px;">
                                                Track your parcel
                                            </a>
                                        @else
                                            <p style="margin:0;font-size:14px;color:#64748b;">
                                                Tracking is not available yet. We will update you when a tracking link is provided.
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            @if ($hasInvoiceAttachment)
                                <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#475569;">
                                    Your invoice is attached to this email as a PDF.
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 28px;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">
                                If you did not expect this message, please contact the seller.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
