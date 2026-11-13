{{ $appName }} — Shipment confirmation

Your order has been dispatched.

Order number: #{{ $orderNumber }}

@if (! empty($trackingNumber))
Tracking number: {{ $trackingNumber }}

@endif
@if (! empty($carrier))
Carrier: {{ $carrier }}

@endif
@if ($trackingUrl)
Track your parcel:
{{ $trackingUrl }}
@else
Tracking link is not available yet.
@endif

@if ($hasInvoiceAttachment)
Your invoice is attached to this email as a PDF.
@endif
