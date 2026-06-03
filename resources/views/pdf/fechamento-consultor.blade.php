<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Fechamento — {{ $consultantName }} — {{ $periodo }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    @page { margin: 1.3cm 1.2cm; }
    @media screen { body { padding: 1.3cm 1.2cm; } }
    .header { border-bottom: 2px solid #7c3aed; padding-bottom: 12px; margin-bottom: 16px; }
    .header table { width: 100%; border-collapse: collapse; }
    .logo img { height: 42px; width: auto; }
    .meta { text-align: right; font-size: 11px; color: #4b5563; line-height: 1.5; }
    .meta strong { font-size: 15px; color: #111827; display: block; margin-bottom: 3px; }
    .brand { font-size: 18px; font-weight: bold; color: #111827; }
    .brand-sub { font-size: 10px; color: #6b7280; margin-top: 2px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { background: #f5f3ff; border: 1px solid #ede9fe; padding: 9px 11px; vertical-align: top; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #7c3aed; font-weight: bold; }
    .summary-value { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }

    .group-title { font-size: 12px; font-weight: bold; color: #5b21b6; background: #ede9fe; padding: 6px 10px; margin-top: 14px; border-radius: 4px; }
    .client-head { margin: 9px 0 4px; padding: 3px 6px; border-bottom: 1px solid #ddd6fe; }
    .client-head table { width: 100%; border-collapse: collapse; }
    .client-name { font-size: 11px; font-weight: bold; color: #111827; }
    .client-total { font-size: 10px; color: #7c3aed; font-weight: bold; text-align: right; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; table-layout: fixed; }
    table.rows th { background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 4px 5px; font-size: 9px; vertical-align: top; word-wrap: break-word; }
    .right { text-align: right; }
    .center { text-align: center; }
    .nowrap { white-space: nowrap; }
    .pcode { color: #9ca3af; }
    .extra { color: #16a34a; font-size: 8px; }
    .section-total { font-size: 11px; font-weight: bold; color: #5b21b6; text-align: right; padding: 4px 6px; }

    .total-box { margin-top: 22px; background: #7c3aed; color: #fff; padding: 12px 16px; border-radius: 6px; width: 100%; border-collapse: collapse; }
    .total-box td { color: #fff; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
    .muted { color: #9ca3af; }
    .empty { padding: 20px; text-align: center; color: #9ca3af; font-style: italic; }
  </style>
</head>
<body>
  <div class="header">
    <table>
      <tr>
        <td style="vertical-align:middle;">
          @if(!empty($logoDataUri))
            <span class="logo"><img src="{{ $logoDataUri }}" alt="ERPSERV Consultoria"></span>
          @else
            <span class="brand">ERPSERV Consultoria</span><div class="brand-sub">Minutor — Controle de horas e contratos</div>
          @endif
        </td>
        <td class="meta" style="vertical-align:middle;">
          <strong>{{ $consultantName }}</strong>
          Fechamento de Consultores &nbsp;·&nbsp; {{ $periodo }} &nbsp;·&nbsp;
          {{ ($mode ?? 'ambos') === 'despesa' ? 'Despesas' : (($mode ?? 'ambos') === 'servicos' ? 'Serviços' : 'Completo') }}
        </td>
      </tr>
    </table>
  </div>

  {{-- Resumo (cards) — espelha o summaryExtra por tipo da tela --}}
  <table class="summary">
    <tr>
      @foreach($cards as $card)
        <td>
          <div class="summary-label">{{ $card['label'] }}</div>
          <div class="summary-value" @if(!empty($card['color']))style="color:{{ $card['color'] }};"@endif>{{ $card['value'] }}</div>
        </td>
      @endforeach
    </tr>
  </table>

  {{-- Apontamentos por tipo de contrato → cliente --}}
  @if(($mode ?? 'ambos') !== 'despesa')
    @if(empty($grupos))
      <div class="empty">Nenhum apontamento no período</div>
    @else
      @foreach($grupos as $grupo)
        <div class="group-title">{{ $grupo['tipo'] }} — {{ $grupo['horas_fmt'] }}</div>
        @foreach($grupo['clientes'] as $cliente)
          <div class="client-head">
            <table><tr>
              <td class="client-name">{{ $cliente['nome'] }}</td>
              <td class="client-total">{{ $cliente['horas_fmt'] }}</td>
            </tr></table>
          </div>
          <table class="rows">
            <thead>
              <tr>
                <th style="width:50px;">Data</th>
                <th style="width:11%;">Cliente</th>
                <th>Projeto</th>
                <th style="width:48px;">Ticket</th>
                <th>Título</th>
                <th class="center" style="width:34px;">Início</th>
                <th class="center" style="width:34px;">Fim</th>
                <th class="right" style="width:66px;">Horas / Extra</th>
              </tr>
            </thead>
            <tbody>
              @foreach($cliente['linhas'] as $l)
                <tr>
                  <td class="nowrap">{{ $l['data'] }}</td>
                  <td>{{ $l['cliente'] }}</td>
                  <td><span class="pcode">{{ $l['codigo'] }}</span> {{ $l['projeto'] }}</td>
                  <td>{{ $l['ticket'] }}</td>
                  <td>{{ $l['titulo'] }}</td>
                  <td class="center nowrap">{{ $l['inicio'] }}</td>
                  <td class="center nowrap">{{ $l['fim'] }}</td>
                  <td class="right nowrap">{{ $l['horas'] }}@if(!empty($l['extra'])) <span class="extra">{{ $l['extra'] }}</span>@endif</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endforeach
        <div class="section-total">Subtotal {{ $grupo['tipo'] }}: {{ $grupo['horas_fmt'] }}</div>
      @endforeach
    @endif
  @endif

  {{-- Despesas reembolsadas no fechamento --}}
  @if(!empty($temDespesas))
    <div class="group-title" style="background:#cffafe;color:#0e7490;">Despesas reembolsadas no fechamento — Saldo: {{ $despesaSaldoFmt }}</div>
    <table class="rows">
      <thead>
        <tr>
          <th style="width:54px;">Data</th>
          <th>Descrição</th>
          <th style="width:74px;">Categoria</th>
          <th style="width:13%;">Cliente</th>
          <th>Projeto</th>
          <th style="width:74px;">Pagamento</th>
          <th class="right" style="width:64px;">Valor</th>
        </tr>
      </thead>
      <tbody>
        @foreach($despesas as $d)
          <tr>
            <td class="nowrap">{{ \Carbon\Carbon::parse($d['data'])->format('d/m/Y') }}</td>
            <td>{{ $d['descricao'] ?: '—' }}</td>
            <td>{{ $d['categoria'] }}</td>
            <td>{{ $d['cliente'] ?? '—' }}</td>
            <td>{{ $d['projeto'] }}</td>
            <td class="nowrap">{{ $d['is_paid'] ? ($d['paid_at'] ? 'Pago '.\Carbon\Carbon::parse($d['paid_at'])->format('d/m/Y') : 'Pago') : 'No fechamento' }}</td>
            <td class="right nowrap">R$ {{ number_format($d['valor'], 2, ',', '.') }}</td>
          </tr>
        @endforeach
        <tr>
          <td colspan="6" class="right" style="font-weight:bold;padding-top:6px;">Saldo a pagar no fechamento</td>
          <td class="right nowrap" style="font-weight:bold;padding-top:6px;">{{ $despesaSaldoFmt }}</td>
        </tr>
      </tbody>
    </table>
  @endif

  {{-- Ajustes do recebimento --}}
  @if(!empty($temAjustes))
    <div class="group-title" style="background:#fef3c7;color:#92400e;">Ajustes do recebimento</div>
    <table class="rows">
      <tbody>
        <tr><td>Serviço</td><td class="muted">—</td><td class="right nowrap">{{ $servTotalFmt }}</td></tr>
        @if($despTot > 0)
          <tr><td>Despesa</td><td class="muted">—</td><td class="right nowrap" style="color:#15803d;">+ {{ $despTotFmt }}</td></tr>
        @endif
        <tr><td>Desconto</td><td class="muted">{{ $descontoDesc ?: '—' }}</td><td class="right nowrap" style="color:#b91c1c;">− {{ $descontoFmt }}</td></tr>
        <tr><td>Adiantamento</td><td class="muted">—</td><td class="right nowrap" style="color:#b91c1c;">− {{ $adiantamentoFmt }}</td></tr>
        <tr><td>Adicional</td><td class="muted">{{ $adicionalDesc ?: '—' }}</td><td class="right nowrap" style="color:#15803d;">+ {{ $adicionalFmt }}</td></tr>
      </tbody>
    </table>
  @endif

  <table class="total-box">
    <tr>
      <td class="total-label">
        @if(!empty($temAjustes))
          RECEBIMENTO <br><span style="font-size:9px;font-weight:normal;">Base {{ $baseValorFmt }} − Desconto {{ $descontoFmt }} − Adiantamento {{ $adiantamentoFmt }} + Adicional {{ $adicionalFmt }}</span>
        @elseif(($mode ?? 'ambos') === 'despesa')
          TOTAL — DESPESAS (FECHAMENTO)
        @elseif(($mode ?? 'ambos') === 'servicos')
          TOTAL A PAGAR — SERVIÇOS
        @elseif($despTot > 0)
          TOTAL A PAGAR <br><span style="font-size:9px;font-weight:normal;">Serviços {{ $servTotalFmt }} &nbsp;+&nbsp; Despesas {{ $despTotFmt }}</span>
        @else
          TOTAL A PAGAR
        @endif
      </td>
      <td class="total-value">{{ $totalValorFmt }}</td>
    </tr>
  </table>
</body>
</html>
