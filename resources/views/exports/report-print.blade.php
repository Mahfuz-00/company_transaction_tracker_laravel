@php
    /**
     * Print-ready report. Opened inline in the browser; the user prints or
     * "Saves as PDF". @page + print rules keep it clean across A4 and Letter.
     */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 32px;
            background: #f8fafc;
        }
        .sheet {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            padding: 36px 40px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
        }
        .head { border-bottom: 2px solid #4f46e5; padding-bottom: 14px; margin-bottom: 18px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { font-size: 12px; color: #64748b; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px 24px; margin: 14px 0 22px; }
        .meta div { font-size: 12px; color: #475569; }
        .meta b { color: #0f172a; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        thead th {
            background: #4f46e5; color: #fff; text-align: left;
            padding: 9px 10px; font-weight: 600; white-space: nowrap;
        }
        tbody td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .foot { margin-top: 22px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; }
        .toolbar { max-width: 1100px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 10px; }
        .toolbar button {
            background: #4f46e5; color: #fff; border: 0; border-radius: 8px;
            padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .toolbar button.ghost { background: #e2e8f0; color: #334155; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
            .toolbar { display: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
        @page { size: A4 landscape; margin: 12mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="ghost" onclick="window.close()">Close</button>
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="sheet">
        <div class="head">
            <h1>{{ $title }}</h1>
            <div class="sub">Generated {{ $generatedAt }}</div>
        </div>

        @if(!empty($meta))
            <div class="meta">
                @foreach($meta as $label => $value)
                    <div>{{ $label }}: <b>{{ $value }}</b></div>
                @endforeach
            </div>
        @endif

        <table>
            <thead>
                <tr>
                    @foreach($columns as $key => $label)
                        @php
                            $isNumericCol = in_array($key, ['amount', 'total', 'total_deposits', 'total_meals', 'meal_cost', 'balance', 'total_dues', 'pool_balance', 'total_expenses', 'total_meal_cost', 'breakfast', 'lunch', 'dinner', 'deposits', 'subsidy'], true);
                        @endphp
                        <th class="{{ $isNumericCol ? 'num' : '' }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        @foreach($columns as $key => $label)
                            @php
                                $raw = data_get($row, $key);
                                $value = $formatter ? $formatter($raw, $key, $row) : $raw;
                                $isNumericCol = in_array($key, ['amount', 'total', 'total_deposits', 'total_meals', 'meal_cost', 'balance', 'total_dues', 'pool_balance', 'total_expenses', 'total_meal_cost', 'breakfast', 'lunch', 'dinner', 'deposits', 'subsidy'], true);
                            @endphp
                            <td class="{{ $isNumericCol ? 'num' : '' }}">{{ $value }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" style="text-align:center; padding:28px; color:#94a3b8;">
                            No records for this selection.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="foot">
            {{ count(is_array($rows) ? $rows : iterator_to_array($rows)) }} row(s) ·
            {{ $title }}
        </div>
    </div>

    <script>
        // Auto-open the print dialog so "Save as PDF" is one click away.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 400);
        });
    </script>
</body>
</html>
