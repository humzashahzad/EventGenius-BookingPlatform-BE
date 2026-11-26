@php
    $m = fn ($v) => \App\Services\InvoiceService::money($v);
    $cur = $invoice['currency'];
    $b = $invoice['booking'];
    $p = $invoice['payment'];
    $t = $invoice['totals'];
    $refunded = $invoice['status'] === 'refunded';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice['invoice_number'] }}</title>
<style>
    @page { margin: 36px 40px 64px 40px; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; color: #1C1917; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .muted { color: #6F6863; }
    .secondary { color: #57534E; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }

    .header td { vertical-align: middle; }
    .brand-name { font-size: 18px; font-weight: bold; color: #1C1917; }
    .brand-tag { font-size: 9px; color: #6F6863; }
    .title { font-size: 24px; font-weight: bold; letter-spacing: 2px; color: #047857; }
    .rule { border-top: 2px solid #047857; margin: 14px 0 16px; }

    .meta td { padding: 2px 0; }
    .meta .label { color: #6F6863; width: 88px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; }
    .badge-paid { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .badge-refunded { background: #F5F3FF; color: #6D28D9; border: 1px solid #DDD6FE; }

    .eyebrow { font-size: 8.5px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #6F6863; margin-bottom: 5px; }
    .party { width: 50%; padding-right: 16px; }
    .party-name { font-size: 11.5px; font-weight: bold; margin-bottom: 2px; }

    .box { border: 1px solid #E5E4E1; border-radius: 6px; padding: 10px 12px; }
    .details td { padding: 3px 0; }
    .details .label { color: #6F6863; width: 92px; }

    .items { margin-top: 6px; }
    .items th.right { text-align: right; }
    .items th { background: #F5F5F4; color: #57534E; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; text-align: left; padding: 7px 10px; border-bottom: 1px solid #E5E4E1; }
    .items td { padding: 9px 10px; border-bottom: 1px solid #E5E4E1; }
    .items .desc { font-weight: bold; }
    .items .sub { color: #6F6863; font-size: 9px; margin-top: 1px; }

    .totals { width: 300px; margin-left: auto; margin-top: 10px; }
    .totals td { padding: 4px 10px; }
    .totals .grand td { border-top: 1px solid #1C1917; padding-top: 7px; padding-bottom: 7px; font-size: 12px; font-weight: bold; }
    .totals .paid td { color: #047857; }
    .totals .refund td { color: #6D28D9; }
    .totals .balance td { background: #F5F5F4; font-weight: bold; }

    .section { margin-top: 18px; }
    .note { margin-top: 18px; padding: 10px 12px; border-left: 3px solid #047857; background: #F5F5F4; color: #57534E; }

    .footer { position: fixed; bottom: -44px; left: 0; right: 0; height: 30px; border-top: 1px solid #E5E4E1; padding-top: 6px; font-size: 8.5px; color: #6F6863; }
</style>
</head>
<body>

<div class="footer">
    <table>
        <tr>
            <td>{{ $invoice['issuer']['name'] }} · {{ $invoice['issuer']['website'] }} · Billing questions: {{ $invoice['issuer']['support_email'] }}</td>
            <td class="right nowrap" style="width: 80px;">&nbsp;</td>
        </tr>
    </table>
</div>

{{-- Header --}}
<table class="header">
    <tr>
        <td style="width: 50%;">
            <table>
                <tr>
                    @if ($logo)
                        <td style="width: 46px;"><img src="{{ $logo }}" width="38" height="38" alt=""></td>
                    @endif
                    <td>
                        <div class="brand-name">{{ $invoice['issuer']['name'] }}</div>
                        <div class="brand-tag">{{ $invoice['issuer']['tagline'] }} · {{ $invoice['issuer']['website'] }}</div>
                    </td>
                </tr>
            </table>
        </td>
        <td class="right">
            <div class="title">INVOICE</div>
            <span class="badge {{ $refunded ? 'badge-refunded' : 'badge-paid' }}">{{ $invoice['status_label'] }}</span>
        </td>
    </tr>
</table>

<div class="rule"></div>

{{-- Parties + invoice meta --}}
<table>
    <tr>
        <td class="party" style="width: 34%;">
            <div class="eyebrow">Billed to</div>
            <div class="party-name">{{ $invoice['customer']['name'] }}</div>
            @if ($invoice['customer']['email'])<div class="secondary">{{ $invoice['customer']['email'] }}</div>@endif
            @if ($invoice['customer']['phone'])<div class="secondary">{{ $invoice['customer']['phone'] }}</div>@endif
        </td>
        <td class="party" style="width: 34%;">
            <div class="eyebrow">Venue host</div>
            <div class="party-name">{{ $invoice['store']['name'] }}</div>
            @if ($invoice['store']['address'])<div class="secondary">{{ $invoice['store']['address'] }}</div>@endif
            @if ($invoice['store']['phone'])<div class="secondary">{{ $invoice['store']['phone'] }}</div>@endif
            @if ($invoice['store']['email'])<div class="secondary">{{ $invoice['store']['email'] }}</div>@endif
        </td>
        <td style="width: 32%;">
            <table class="meta">
                <tr><td class="label">Invoice no.</td><td class="right"><strong>{{ $invoice['invoice_number'] }}</strong></td></tr>
                <tr><td class="label">Issue date</td><td class="right">{{ $invoice['issued_on'] }}</td></tr>
                <tr><td class="label">Booking</td><td class="right">{{ $b['booking_number'] }}</td></tr>
                <tr><td class="label">Amount</td><td class="right"><strong>{{ $cur }} {{ $m($t['total']) }}</strong></td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Booking details --}}
<div class="section">
    <div class="eyebrow">Booking details</div>
    <div class="box">
        <table class="details">
            <tr>
                <td style="width: 50%; padding-right: 12px;">
                    <table>
                        <tr><td class="label">Booking no.</td><td>{{ $b['booking_number'] }}</td></tr>
                        <tr><td class="label">Venue</td><td>{{ $b['venue']['name'] }}</td></tr>
                        @if ($b['venue']['address'])<tr><td class="label">Address</td><td>{{ $b['venue']['address'] }}</td></tr>@endif
                        <tr><td class="label">Guests</td><td>{{ number_format($b['guests']) }}</td></tr>
                    </table>
                </td>
                <td style="width: 50%;">
                    <table>
                        <tr><td class="label">Event</td><td>{{ $b['event_name'] }}</td></tr>
                        @if ($b['event_type_label'])<tr><td class="label">Event type</td><td>{{ $b['event_type_label'] }}</td></tr>@endif
                        <tr><td class="label">Event date</td><td>{{ $b['event_date_label'] }}</td></tr>
                        @if ($b['time_label'])<tr><td class="label">Time</td><td>{{ $b['time_label'] }}@if ($b['duration_hours']) ({{ $b['duration_hours'] }} hours)@endif</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- Line items --}}
<div class="section">
    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right" style="width: 70px;">Qty</th>
                <th class="right" style="width: 110px;">Unit price ({{ $cur }})</th>
                <th class="right" style="width: 120px;">Amount ({{ $cur }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['line_items'] as $item)
                <tr>
                    <td>
                        <div class="desc">{{ $item['description'] }}</div>
                        @if ($item['detail'])<div class="sub">{{ $item['detail'] }}</div>@endif
                    </td>
                    <td class="right">{{ number_format($item['quantity']) }}</td>
                    <td class="right nowrap">{{ $m($item['unit_price']) }}</td>
                    <td class="right nowrap">{{ $m($item['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="secondary">Subtotal</td><td class="right nowrap">{{ $cur }} {{ $m($t['subtotal']) }}</td></tr>
        @if ($t['discount'] > 0)
            <tr><td class="secondary">Discount</td><td class="right nowrap">− {{ $cur }} {{ $m($t['discount']) }}</td></tr>
        @endif
        <tr><td class="secondary">{{ $t['tax_label'] }}</td><td class="right nowrap">{{ $cur }} {{ $m($t['tax']) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="right nowrap">{{ $cur }} {{ $m($t['total']) }}</td></tr>
        <tr class="paid"><td>Amount paid</td><td class="right nowrap">{{ $cur }} {{ $m($t['amount_paid']) }}</td></tr>
        @if ($refunded)
            <tr class="refund"><td>Refunded</td><td class="right nowrap">− {{ $cur }} {{ $m($t['refunded']) }}</td></tr>
        @endif
        <tr class="balance"><td>Balance due</td><td class="right nowrap">{{ $cur }} {{ $m($t['balance_due']) }}</td></tr>
    </table>
</div>

{{-- Payment details --}}
<div class="section">
    <div class="eyebrow">Payment details</div>
    <div class="box">
        <table class="details">
            <tr>
                <td style="width: 50%; padding-right: 12px;">
                    <table>
                        <tr><td class="label">Method</td><td>{{ $p['method_label'] }}</td></tr>
                        @if ($p['transaction_id'])<tr><td class="label">Transaction ID</td><td>{{ $p['transaction_id'] }}</td></tr>@endif
                        @if ($p['reference'])<tr><td class="label">Reference</td><td>{{ $p['reference'] }}</td></tr>@endif
                    </table>
                </td>
                <td style="width: 50%;">
                    <table>
                        @if ($p['paid_at_label'])<tr><td class="label">Paid at</td><td>{{ $p['paid_at_label'] }}</td></tr>@endif
                        @if ($p['gateway_amount'])
                            <tr><td class="label">Charged</td><td>{{ $p['gateway_currency'] }} {{ $m($p['gateway_amount']) }}</td></tr>
                            @if ($p['exchange_rate'])<tr><td class="label">Exchange rate</td><td>1 {{ $cur }} = {{ rtrim(rtrim(number_format($p['exchange_rate'], 6), '0'), '.') }} {{ $p['gateway_currency'] }}</td></tr>@endif
                        @endif
                        @if ($refunded && $p['refunded_at_label'])<tr><td class="label">Refunded at</td><td>{{ $p['refunded_at_label'] }}</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>
    </div>
</div>

<div class="note">
    @if ($refunded)
        This payment was refunded in full. Keep this invoice for your records.
    @else
        Thank you for booking with {{ $invoice['issuer']['name'] }}. Your payment has been received and your date is secured.
    @endif
    For questions about this invoice, contact {{ $invoice['issuer']['support_email'] }} and quote {{ $invoice['invoice_number'] }}.
</div>

</body>
</html>
