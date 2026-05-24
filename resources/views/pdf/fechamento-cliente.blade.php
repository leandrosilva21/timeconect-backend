<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatório de Apontamentos — {{ $clienteName }} — {{ $periodo }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    .header { border-bottom: 2px solid #0e7490; padding-bottom: 12px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111827; }
    .brand-sub { font-size: 10px; color: #6b7280; margin-top: 2px; }
    .doc-title { font-size: 15px; font-weight: bold; color: #155e75; margin-top: 10px; }
    .doc-meta { font-size: 11px; color: #4b5563; margin-top: 2px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { width: 50%; background: #ecfeff; border: 1px solid #cffafe; padding: 10px 12px; vertical-align: top; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #0e7490; font-weight: bold; }
    .summary-value { font-size: 15px; font-weight: bold; color: #111827; margin-top: 3px; }

    .group-title { font-size: 12px; font-weight: bold; color: #155e75; background: #cffafe; padding: 6px 10px; margin-top: 14px; border-radius: 4px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.rows th { background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; font-size: 10px; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    .section-total { font-size: 11px; font-weight: bold; color: #155e75; text-align: right; padding: 4px 6px; }

    /* Sub-projeto (filho consolidado dentro do contrato) — destaque roxo. */
    .sub-title { font-size: 11px; font-weight: bold; color: #5b21b6; background: #f5f3ff; padding: 4px 10px; margin-top: 8px; border-left: 3px solid #8b5cf6; }
    .sub-total { font-size: 10px; font-weight: bold; color: #5b21b6; text-align: right; padding: 3px 6px 2px; }

    .total-box { margin-top: 22px; background: #0e7490; color: #fff; padding: 12px 16px; border-radius: 6px; }
    .total-box td { color: #fff; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
    .muted { color: #9ca3af; }
    .empty { padding: 20px; text-align: center; color: #9ca3af; font-style: italic; }

    /* Apuração por Ticket (Vedamotors) — destaque roxo, print-friendly. */
    .ticket-section { margin-top: 22px; }
    .ticket-section h2 { font-size: 13px; font-weight: bold; color: #5b21b6; margin: 0 0 4px; }
    .ticket-sub { font-size: 9px; color: #6b7280; margin: 0 0 8px; }
    table.ticket-table { width: 100%; border-collapse: collapse; }
    table.ticket-table th { background: #f5f3ff; border-bottom: 1px solid #ddd6fe; text-align: left; padding: 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #5b21b6; }
    table.ticket-table td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; font-size: 10px; vertical-align: top; }
    table.ticket-table td.veda-life, table.ticket-table th.veda-life { color: #5b21b6; }
    table.ticket-table tfoot td { border-top: 2px solid #ddd6fe; border-bottom: none; font-weight: bold; color: #5b21b6; background: #f5f3ff; padding: 7px 6px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand">ERPSERV Consultoria</div>
    <div class="brand-sub">Minutor — Controle de horas e contratos</div>
    <div class="doc-title">Relatório de Apontamentos</div>
    <div class="doc-meta">{{ $clienteName }} &nbsp;·&nbsp; Competência: {{ $periodo }}</div>
  </div>

  <table class="summary">
    <tr>
      <td>
        <div class="summary-label">Total de Horas</div>
        <div class="summary-value">{{ $totalHorasFmt }}</div>
      </td>
      <td>
        <div class="summary-label">Valor a Pagar</div>
        <div class="summary-value" style="color:#0e7490;">{{ $valorTotal }}</div>
      </td>
    </tr>
  </table>

  @if(empty($grupos))
    <div class="empty">Nenhum apontamento considerado no período.</div>
  @else
    @foreach($grupos as $grupo)
      <div class="group-title">{{ $grupo['projeto'] }} — {{ $grupo['horas_fmt'] }}</div>
      @if(!empty($grupo['multi_sub']))
        {{-- Contrato consolidado: um bloco por sub-projeto (filho), com sub-subtotal. --}}
        @foreach($grupo['subgrupos'] as $sub)
          <div class="sub-title">{{ $sub['projeto'] }}</div>
          <table class="rows">
            <thead>
              <tr>
                <th class="nowrap" style="width:62px;">Data</th>
                <th>Consultor</th>
                <th style="width:96px;">{{ ($vedamotors ?? false) ? 'Ticket ERPSERV' : 'Ticket' }}</th>
                <th>{{ ($vedamotors ?? false) ? 'Ticket Vedamotors' : 'Título' }}</th>
                <th class="right nowrap" style="width:54px;">Horas</th>
              </tr>
            </thead>
            <tbody>
              @foreach($sub['linhas'] as $l)
                <tr>
                  <td class="nowrap">{{ $l['data'] }}</td>
                  <td>{{ $l['consultor'] }}</td>
                  <td>{{ $l['ticket'] ?: '—' }}</td>
                  <td>{{ $l['titulo'] ?: '—' }}</td>
                  <td class="right nowrap">{{ $l['horas_fmt'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
          <div class="sub-total">Subtotal {{ $sub['projeto'] }}: {{ $sub['horas_fmt'] }}</div>
        @endforeach
      @else
        {{-- Caso normal (um único sub-projeto): renderiza plano, como antes. --}}
        <table class="rows">
          <thead>
            <tr>
              <th class="nowrap" style="width:62px;">Data</th>
              <th>Consultor</th>
              <th style="width:96px;">{{ ($vedamotors ?? false) ? 'Ticket ERPSERV' : 'Ticket' }}</th>
              <th>{{ ($vedamotors ?? false) ? 'Ticket Vedamotors' : 'Título' }}</th>
              <th class="right nowrap" style="width:54px;">Horas</th>
            </tr>
          </thead>
          <tbody>
            @foreach($grupo['linhas'] as $l)
              <tr>
                <td class="nowrap">{{ $l['data'] }}</td>
                <td>{{ $l['consultor'] }}</td>
                <td>{{ $l['ticket'] ?: '—' }}</td>
                <td>{{ $l['titulo'] ?: '—' }}</td>
                <td class="right nowrap">{{ $l['horas_fmt'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
      <div class="section-total">Subtotal {{ $grupo['projeto'] }}: {{ $grupo['horas_fmt'] }}</div>
    @endforeach
  @endif

  @if(!empty($vedamotors) && count($ticketRows ?? []))
    <div class="ticket-section">
      <h2>Apuração por Ticket</h2>
      <p class="ticket-sub">Tickets com apontamento dentro do período, mostrando o total no período selecionado e o total acumulado desde o primeiro apontamento no sistema (mesmo cliente).</p>
      <table class="ticket-table">
        <thead>
          <tr>
            <th style="width:70px;">Ticket</th>
            <th class="nowrap" style="width:96px;">Ticket Vedamotors</th>
            <th>Solicitante</th>
            <th class="right nowrap" style="width:90px;">Total no período</th>
            <th class="right nowrap veda-life" style="width:90px;">Total histórico</th>
          </tr>
        </thead>
        <tbody>
          @foreach($ticketRows as $tk)
            <tr>
              <td class="nowrap">#{{ $tk['ticket'] }}</td>
              <td class="nowrap">{{ $tk['veda_ticket'] }}</td>
              <td>{{ $tk['requester'] ?: '—' }}</td>
              <td class="right nowrap">{{ $tk['period_fmt'] }}</td>
              <td class="right nowrap veda-life">{{ $tk['lifetime_fmt'] }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="right">Totais ({{ count($ticketRows) }} ticket{{ count($ticketRows) === 1 ? '' : 's' }})</td>
            <td class="right nowrap">{{ $ticketTotPeriodFmt }}</td>
            <td class="right nowrap veda-life">{{ $ticketTotLifetimeFmt }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  @endif

  <table class="total-box" width="100%">
    <tr>
      <td class="total-label">VALOR A PAGAR — {{ strtoupper($clienteName) }}</td>
      <td class="total-value">{{ $valorTotal }}</td>
    </tr>
  </table>
</body>
</html>
