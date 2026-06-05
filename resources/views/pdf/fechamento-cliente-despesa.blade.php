<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatório de Despesas — {{ $clienteName }} — {{ $periodo }}</title>
  <style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    /* Margem do documento: por página no PDF (@page) e como padding no preview em tela. */
    @page { margin: 1.3cm 1.5cm; }
    @media screen { body { padding: 1.3cm 1.5cm; } }

    /* Cabeçalho — logo à esquerda + identificação à direita, régua teal embaixo. */
    table.header { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0e7490; margin-bottom: 18px; }
    table.header td { padding: 0 0 14px; vertical-align: middle; }
    .hd-logo { width: 50%; }
    .hd-logo img { width: 150px; max-width: 100%; height: auto; }
    .brand { font-size: 20px; font-weight: bold; color: #0e7490; }
    .hd-meta { text-align: right; }
    .doc-title { font-size: 18px; font-weight: bold; color: #1f2937; }
    .doc-sub { font-size: 11px; color: #6b7280; margin: 1px 0 6px; }
    .doc-line { font-size: 11px; color: #4b5563; margin-top: 1px; }
    .doc-line .lbl { font-weight: bold; color: #374151; }
    .doc-emit { font-size: 10px; color: #9ca3af; margin-top: 3px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { width: 50%; background: #ecfeff; border: 1px solid #a5f0f7; padding: 10px 12px; vertical-align: top; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #0e7490; font-weight: bold; }
    .summary-value { font-size: 15px; font-weight: bold; color: #111827; margin-top: 3px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.rows th { background: #ecfeff; border-bottom: 1px solid #a5f0f7; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #0e7490; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; font-size: 10px; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }

    .total-box { margin-top: 22px; background: #0e7490; color: #fff; padding: 12px 16px; border-radius: 6px; }
    .total-box td { color: #fff; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
    .empty { padding: 20px; text-align: center; color: #9ca3af; font-style: italic; }
  </style>
</head>
<body>
  <table class="header">
    <tr>
      <td class="hd-logo">
        @if(!empty($logoDataUri))
          <img src="{{ $logoDataUri }}" alt="ERPSERV Consultoria" />
        @else
          <span class="brand">ERPSERV Consultoria</span>
        @endif
      </td>
      <td class="hd-meta">
        <div class="doc-title">Relatório de Despesas</div>
        <div class="doc-sub">Despesas a cobrar no período</div>
        <div class="doc-line"><span class="lbl">Cliente:</span> {{ $clienteName }}</div>
        <div class="doc-line"><span class="lbl">Competência:</span> {{ $periodo }}</div>
        <div class="doc-emit">Emitido em {{ $emitidoEm ?? '' }}</div>
      </td>
    </tr>
  </table>

  <table class="summary">
    <tr>
      <td>
        <div class="summary-label">Despesas a cobrar</div>
        <div class="summary-value">{{ $qtd }}</div>
      </td>
      <td>
        <div class="summary-label">Total a Cobrar</div>
        <div class="summary-value" style="color:#0e7490;">{{ $totalFmt }}</div>
      </td>
    </tr>
  </table>

  @if(empty($despesas))
    <div class="empty">Nenhuma despesa a cobrar no período.</div>
  @else
    <table class="rows">
      <thead>
        <tr>
          <th class="nowrap" style="width:62px;">Data</th>
          <th>Descrição</th>
          <th style="width:90px;">Categoria</th>
          <th>Colaborador</th>
          <th>Projeto</th>
          <th class="right nowrap" style="width:84px;">Valor</th>
        </tr>
      </thead>
      <tbody>
        @foreach($despesas as $d)
          <tr>
            <td class="nowrap">{{ $d['data'] }}</td>
            <td>{{ $d['descricao'] }}</td>
            <td>{{ $d['categoria'] }}</td>
            <td>{{ $d['colaborador'] }}</td>
            <td>{{ $d['projeto'] }}</td>
            <td class="right nowrap">{{ $d['valor'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <table class="total-box" width="100%">
    <tr>
      <td class="total-label">TOTAL A COBRAR — {{ strtoupper($clienteName) }}</td>
      <td class="total-value">{{ $totalFmt }}</td>
    </tr>
  </table>
</body>
</html>
