<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Fechamento — {{ $parceiroName }} — {{ $periodo }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    /* Margem do documento: por página no PDF (@page) e como padding no preview em tela. */
    @page { margin: 1.3cm 1.5cm; }
    @media screen { body { padding: 1.3cm 1.5cm; } }
    .header { border-bottom: 2px solid #7c3aed; padding-bottom: 12px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111827; }
    .brand-sub { font-size: 10px; color: #6b7280; margin-top: 2px; }
    .doc-title { font-size: 15px; font-weight: bold; color: #5b21b6; margin-top: 10px; }
    .doc-meta { font-size: 11px; color: #4b5563; margin-top: 2px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { width: 50%; background: #f5f3ff; border: 1px solid #ede9fe; padding: 10px 12px; vertical-align: top; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #7c3aed; font-weight: bold; }
    .summary-value { font-size: 15px; font-weight: bold; color: #111827; margin-top: 3px; }

    .group-title { font-size: 12px; font-weight: bold; color: #5b21b6; background: #ede9fe; padding: 6px 10px; margin-top: 14px; border-radius: 4px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.rows th { background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; font-size: 10px; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    .section-total { font-size: 11px; font-weight: bold; color: #5b21b6; text-align: right; padding: 4px 6px; }

    .total-box { margin-top: 22px; background: #7c3aed; color: #fff; padding: 12px 16px; border-radius: 6px; }
    .total-box td { color: #fff; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
    .muted { color: #9ca3af; }
    .empty { padding: 20px; text-align: center; color: #9ca3af; font-style: italic; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand">ERPSERV Consultoria</div>
    <div class="brand-sub">Minutor — Controle de horas e contratos</div>
    <div class="doc-title">Fechamento de Parceiro</div>
    <div class="doc-meta">{{ $parceiroName }} &nbsp;·&nbsp; Período: {{ $periodo }}</div>
  </div>

  <table class="summary">
    <tr>
      <td>
        <div class="summary-label">Total de Horas</div>
        <div class="summary-value">{{ $totalHorasFmt }}</div>
      </td>
      <td>
        <div class="summary-label">Total a Pagar</div>
        <div class="summary-value" style="color:#7c3aed;">{{ $valorTotal }}</div>
      </td>
    </tr>
  </table>

  @if(($mode ?? 'ambos') !== 'despesa')
  @if(empty($grupos))
    <div class="empty">Nenhum apontamento considerado no período.</div>
  @else
    @foreach($grupos as $grupo)
      <div class="group-title">{{ $grupo['consultor'] }} — {{ $grupo['horas_fmt'] }}</div>
      <table class="rows">
        <thead>
          <tr>
            <th class="nowrap" style="width:62px;">Data</th>
            <th>Projeto</th>
            <th style="width:90px;">Ticket</th>
            <th class="right nowrap" style="width:54px;">Horas</th>
          </tr>
        </thead>
        <tbody>
          @foreach($grupo['linhas'] as $l)
            <tr>
              <td class="nowrap">{{ $l['data'] }}</td>
              <td>{{ $l['projeto'] }}</td>
              <td>{{ $l['ticket'] ?: '—' }}</td>
              <td class="right nowrap">{{ $l['horas_fmt'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="section-total">Subtotal {{ $grupo['consultor'] }}: {{ $grupo['horas_fmt'] }}</div>
    @endforeach
  @endif
  @endif

  {{-- ── Despesas (pagas junto no fechamento) ── --}}
  @if(!empty($despesas) || !empty($despesasAntecip))
    <div class="group-title" style="margin-top:18px;background:#cffafe;color:#0e7490;">Despesas</div>
    @if(!empty($despesas))
      <table class="rows">
        <thead>
          <tr>
            <th class="nowrap" style="width:56px;">Data</th>
            <th>Descrição</th>
            <th style="width:78px;">Categoria</th>
            <th>Colaborador</th>
            <th>Cliente</th>
            <th>Projeto</th>
            <th class="right nowrap" style="width:78px;">Valor</th>
          </tr>
        </thead>
        <tbody>
          @foreach($despesas as $d)
            <tr>
              <td class="nowrap">{{ \Carbon\Carbon::parse($d['data'])->format('d/m/Y') }}</td>
              <td>{{ $d['descricao'] }}</td>
              <td>{{ $d['categoria'] }}</td>
              <td>{{ $d['colaborador'] }}</td>
              <td>{{ $d['cliente'] ?? '—' }}</td>
              <td>{{ $d['projeto'] ?? '—' }}</td>
              <td class="right nowrap">{{ $brl($d['valor']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="section-total" style="color:#0e7490;">Saldo a pagar no fechamento: {{ $totalDespesasFmt }}</div>
    @endif

    @if(!empty($despesasAntecip))
      <div class="group-title" style="margin-top:10px; color:#92400e;">Despesas pagas antecipadamente — fora do fechamento (não inclusas no total)</div>
      <table class="rows">
        <thead>
          <tr>
            <th class="nowrap" style="width:56px;">Data</th>
            <th>Descrição</th>
            <th>Colaborador</th>
            <th>Cliente</th>
            <th>Projeto</th>
            <th class="nowrap" style="width:118px;">Paga em</th>
            <th class="right nowrap" style="width:78px;">Valor</th>
          </tr>
        </thead>
        <tbody>
          @foreach($despesasAntecip as $d)
            <tr style="color:#92400e;">
              <td class="nowrap">{{ \Carbon\Carbon::parse($d['data'])->format('d/m/Y') }}</td>
              <td>{{ $d['descricao'] }}</td>
              <td>{{ $d['colaborador'] }}</td>
              <td>{{ $d['cliente'] ?? '—' }}</td>
              <td>{{ $d['projeto'] ?? '—' }}</td>
              <td class="nowrap">{{ $d['paid_at'] ? \Carbon\Carbon::parse($d['paid_at'])->format('d/m/Y') : '—' }}{{ $d['paid_by_name'] ? ' · '.$d['paid_by_name'] : '' }}</td>
              <td class="right nowrap" style="text-decoration:line-through;">{{ $brl($d['valor']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  @endif

  <table width="100%" style="margin-top:18px; font-size:11px;">
    @if(($mode ?? 'ambos') !== 'despesa')<tr><td style="color:#555; padding:2px 6px;">Serviços</td><td class="right" style="padding:2px 6px;">{{ $totalServicosFmt }}</td></tr>@endif
    @if(($mode ?? 'ambos') !== 'servicos')<tr><td style="color:#555; padding:2px 6px;">Despesas (no fechamento)</td><td class="right" style="padding:2px 6px;">{{ $totalDespesasFmt }}</td></tr>@endif
  </table>
  <table class="total-box" width="100%">
    <tr>
      <td class="total-label">{{ ($mode ?? 'ambos') === 'despesa' ? 'TOTAL — DESPESAS' : (($mode ?? 'ambos') === 'servicos' ? 'TOTAL A PAGAR — SERVIÇOS' : 'TOTAL A PAGAR') }} — {{ strtoupper($parceiroName) }}</td>
      <td class="total-value">{{ $valorTotal }}</td>
    </tr>
  </table>
</body>
</html>
