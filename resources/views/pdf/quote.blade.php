<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #172033; font-size: 11px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        .muted { color: #667085; }
        .header, .parties { width: 100%; margin-bottom: 24px; }
        .header td, .parties td { vertical-align: top; width: 50%; }
        table.items { border-collapse: collapse; width: 100%; }
        table.items th { background: #eef2f7; text-align: left; padding: 8px; }
        table.items td { border-bottom: 1px solid #dfe5ec; padding: 8px; vertical-align: top; }
        .number { text-align: right; white-space: nowrap; }
        .totals { width: 42%; margin-left: 58%; margin-top: 16px; border-collapse: collapse; }
        .totals td { padding: 5px 8px; }
        .totals .grand td { border-top: 2px solid #172033; font-size: 14px; font-weight: bold; }
        .section { margin-top: 24px; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td><h1>Cotización</h1><div class="muted">{{ $quote->tenant->name }}</div></td>
        <td class="number"><strong>{{ $quote->number }}</strong><br>Versión {{ $quote->version }}<br>{{ optional($quote->issued_at ?? $quote->created_at)->format('Y-m-d') }}</td>
    </tr>
</table>
<table class="parties">
    <tr>
        <td><strong>Cliente</strong><br>{{ $quote->organization?->name ?? trim(($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? '')) }}<br>{{ $quote->contact?->email ?? $quote->organization?->email }}</td>
        <td><strong>Válida hasta</strong><br>{{ $quote->valid_until?->format('Y-m-d') ?? 'Sin fecha de expiración' }}</td>
    </tr>
</table>
<table class="items">
    <thead><tr><th>Producto / servicio</th><th class="number">Cantidad</th><th class="number">Precio</th><th class="number">Descuento</th><th class="number">Impuesto</th><th class="number">Total</th></tr></thead>
    <tbody>
    @foreach ($quote->items as $item)
        <tr>
            <td><strong>{{ $item->name }}</strong>@if($item->sku)<br><span class="muted">{{ $item->sku }}</span>@endif @if($item->description)<br>{{ $item->description }}@endif</td>
            <td class="number">{{ $item->quantity }}</td>
            <td class="number">{{ $quote->currency->symbol }} {{ $item->unit_price }}</td>
            <td class="number">{{ $quote->currency->symbol }} {{ $item->discount_total }}</td>
            <td class="number">{{ $quote->currency->symbol }} {{ $item->tax_total }}</td>
            <td class="number">{{ $quote->currency->symbol }} {{ $item->total }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<table class="totals">
    <tr><td>Subtotal</td><td class="number">{{ $quote->currency->symbol }} {{ $quote->subtotal }}</td></tr>
    <tr><td>Descuentos</td><td class="number">− {{ $quote->currency->symbol }} {{ $quote->discount_total }}</td></tr>
    <tr><td>Impuestos</td><td class="number">{{ $quote->currency->symbol }} {{ $quote->tax_total }}</td></tr>
    <tr class="grand"><td>Total</td><td class="number">{{ $quote->currency->symbol }} {{ $quote->grand_total }} {{ $quote->currency->code }}</td></tr>
</table>
@if($quote->notes)<div class="section"><strong>Notas</strong><p>{{ $quote->notes }}</p></div>@endif
@if($quote->terms)<div class="section"><strong>Términos</strong><p>{{ $quote->terms }}</p></div>@endif
</body>
</html>
