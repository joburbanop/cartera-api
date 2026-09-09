<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hoja de vida</title>
    <style>
        body { font-family: Arial, sans-serif; color: #0f172a; font-size: 10px; margin: 18px; }
        h2, p { margin: 0 0 8px; }
        .muted { color: #475569; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 12px; }
        .meta { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
        .meta div { width: 48%; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 6px; }
        th { background: #e2e8f0; text-transform: uppercase; font-size: 9px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Hoja de vida del lote {{ $header['lot_number'] }}</h2>
        <p class="muted">{{ $header['note'] }}</p>
    </div>

    <div class="meta">
        <div>
            <p><strong>Proyecto:</strong> {{ $project?->name ?? '—' }}</p>
            <p><strong>Valor:</strong> $ {{ number_format((float) $header['sale_price'], 2, ',', '.') }}</p>
            <p><strong>VALOR LOTE FINANCIADO:</strong> $ {{ number_format((float) $header['financed_value'], 2, ',', '.') }}</p>
            <p><strong>Área / vr m²:</strong>
                {{ $header['area_m2'] ? number_format((float) $header['area_m2'], 2, ',', '.') : '—' }}
                /
                {{ $header['price_m2'] ? '$ '.number_format((float) $header['price_m2'], 0, ',', '.') : '—' }}
            </p>
            <p><strong>Cuota inicial:</strong> $ {{ number_format((float) $header['down_payment'], 2, ',', '.') }}</p>
            <p><strong>Valor cuota:</strong>
                {{ $header['monthly_quota'] ? '$ '.number_format((float) $header['monthly_quota'], 2, ',', '.') : '—' }}
            </p>
        </div>
        <div>
            <p><strong>Cliente:</strong> {{ $header['customer_name'] }}</p>
            <p><strong>Documento:</strong> {{ $header['document_number'] ?? '—' }}</p>
            <p><strong>Dirección:</strong> {{ $header['address'] ?? '—' }}</p>
            <p><strong>Correo:</strong> {{ $header['email'] ?? '—' }}</p>
            <p><strong>Celular:</strong> {{ $header['phone'] ?? '—' }}</p>
            <p><strong>Plazo:</strong> {{ $header['term_months'] }} meses</p>
            <p><strong>Vendido por:</strong> {{ $header['seller_name'] ?? '—' }}</p>
        </div>
    </div>

    <p><strong>Total pagado a la fecha:</strong> $ {{ number_format((float) $summary['collected'], 2, ',', '.') }}
        &nbsp;·&nbsp;
        <strong>A capital:</strong> $ {{ number_format((float) $summary['principal_paid'], 2, ',', '.') }}
        &nbsp;·&nbsp;
        <strong>A interés:</strong> $ {{ number_format((float) $summary['interest_paid'], 2, ',', '.') }}
        @if ((float) $summary['unimputed'] > 0)
            &nbsp;·&nbsp;
            <strong>Sin imputar:</strong> $ {{ number_format((float) $summary['unimputed'], 2, ',', '.') }}
        @endif
    </p>

    <p><strong>Capital insoluto:</strong> $ {{ number_format((float) $summary['outstanding_capital'], 2, ',', '.') }}
        &nbsp;·&nbsp;
        <strong>{{ $summary['criteria_gap_label'] }}:</strong> $ {{ number_format((float) $summary['criteria_gap'], 2, ',', '.') }}
    </p>
    <p class="muted">{{ $summary['criteria_gap_hint'] }}</p>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Concepto</th>
                <th>Recibo #</th>
                <th>Efectivo</th>
                <th>Bancolombia</th>
                <th>Occidente 6391</th>
                <th>Total pagado</th>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="6">SALDO INICIAL</td>
                <td class="right">$ 0,00</td>
                <td class="right">$ {{ number_format((float) $header['financed_value'], 2, ',', '.') }}</td>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d/m/Y') : '—' }}</td>
                    <td>{{ $row['concept'] }}</td>
                    <td>{{ $row['receipt_number'] ?? '—' }}</td>
                    <td class="right">{{ (float) $row['efectivo'] > 0 ? '$ '.number_format((float) $row['efectivo'], 2, ',', '.') : '' }}</td>
                    <td class="right">{{ (float) $row['bancolombia'] > 0 ? '$ '.number_format((float) $row['bancolombia'], 2, ',', '.') : '' }}</td>
                    <td class="right">{{ (float) $row['occidente'] > 0 ? '$ '.number_format((float) $row['occidente'], 2, ',', '.') : '' }}</td>
                    <td class="right">$ {{ number_format((float) $row['total_paid'], 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row['balance'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
