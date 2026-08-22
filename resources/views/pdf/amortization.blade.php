<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Extracto de amortización</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #0f172a;
            font-size: 11px;
            margin: 24px;
        }
        h1, h2, h3, p {
            margin: 0 0 10px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .meta {
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }
        .meta div {
            width: 48%;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #e2e8f0;
            font-size: 10px;
            text-transform: uppercase;
        }
        .muted {
            color: #475569;
        }
        .right {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h2>Extracto de amortización</h2>
            <p class="muted">{{ $type === 'client' ? 'Versión para cliente' : 'Versión interna' }}</p>
        </div>
        <div class="right">
            <p><strong>Versión:</strong> {{ $version }}</p>
            <p><strong>Contrato:</strong> {{ $contract->id }}</p>
        </div>
    </div>

    <div class="meta">
        <div>
            <p><strong>Cliente:</strong> {{ $customer?->name ?? $customer?->first_name ?? 'No disponible' }}</p>
            <p><strong>Proyecto:</strong> {{ $project?->name ?? 'No disponible' }}</p>
            <p><strong>Lote:</strong> {{ $lot?->number ?? 'No disponible' }}</p>
        </div>
        <div>
            <p><strong>Fecha de inicio:</strong> {{ optional($contract->start_date)->format('d/m/Y') ?? '--' }}</p>
            <p><strong>Plazo:</strong> {{ $contract->term_months }} meses</p>
            <p><strong>Tasa:</strong> {{ $contract->interest_rate }}%</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Cuota</th>
                <th>Vence</th>
                <th>Cuota</th>
                <th>Interés</th>
                <th>Capital</th>
                <th>Saldo</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan as $row)
                <tr>
                    <td>{{ $row->installment_number === 0 ? 'Inicial' : $row->installment_number }}</td>
                    <td>{{ optional($row->due_date)->format('d/m/Y') ?? '--' }}</td>
                    <td class="right">$ {{ number_format((float) $row->installment_value, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row->interest_value, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row->principal_value, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row->remaining_balance, 2, ',', '.') }}</td>
                    <td>{{ $row->status->value ?? $row->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
