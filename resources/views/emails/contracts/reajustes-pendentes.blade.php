<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Contratos pendentes de reajuste — {{ $referencia }}</title></head>
@php
    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $data = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
@endphp
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:680px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    <!-- Cabeçalho -->
    <tr>
      <td style="padding:28px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.22em;color:#F87171;font-weight:800;text-transform:uppercase;">ERPSERV · Reajuste de contratos</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:22px;line-height:1.3;font-weight:800;">
          ⚠️ {{ count($contratos) }} contrato(s) com reajuste vencido
        </h1>
        <p style="margin:0;color:#D4D4D8;font-size:14px;line-height:1.55;">
          No fechamento de <b style="color:#FFFFFF;">{{ $referencia }}</b>, os contratos abaixo estão
          <b style="color:#F87171;">pendentes de reajuste</b>. Verifique e aplique o reajuste no dashboard.
        </p>
      </td>
    </tr>

    <!-- Card de impacto total -->
    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#1A1410;border:1px solid #B45309;border-radius:12px;padding:18px 22px;">
          <div style="font-size:10px;letter-spacing:.24em;color:#FBBF24;font-weight:800;text-transform:uppercase;">Impacto estimado total</div>
          <div style="margin-top:6px;color:#FBBF24;font-size:26px;font-weight:800;">{{ $brl($totalImpacto) }}</div>
          <div style="color:#A1A1AA;font-size:12px;margin-top:2px;">soma do reajuste estimado dos contratos vencidos (por período)</div>
        </div>
      </td>
    </tr>

    <!-- Tabela de contratos -->
    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:8px;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
            <tr>
              <td style="padding:8px 10px;color:#C4B5FD;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid #3F3F46;">Cliente</td>
              <td style="padding:8px 10px;color:#C4B5FD;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid #3F3F46;text-align:right;">Valor atual</td>
              <td style="padding:8px 10px;color:#C4B5FD;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid #3F3F46;">Vencimento</td>
              <td style="padding:8px 10px;color:#C4B5FD;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid #3F3F46;">Índice</td>
              <td style="padding:8px 10px;color:#C4B5FD;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid #3F3F46;text-align:right;">Impacto est.</td>
            </tr>
            @foreach ($contratos as $c)
            <tr>
              <td style="padding:10px;border-bottom:1px solid #27272A;">
                <div style="color:#FFFFFF;font-weight:700;font-size:13px;">{{ $c['cliente_nome'] ?? '—' }}</div>
                <div style="color:#71717A;font-size:11px;">{{ $c['codigo'] ?? '—' }}</div>
              </td>
              <td style="padding:10px;border-bottom:1px solid #27272A;color:#E4E4E7;font-size:13px;text-align:right;white-space:nowrap;">{{ $brl($c['valor_atual']) }}</td>
              <td style="padding:10px;border-bottom:1px solid #27272A;font-size:13px;white-space:nowrap;">
                <span style="color:#F87171;font-weight:700;">{{ $data($c['data_proximo_reajuste']) }}</span>
                @if(($c['dias_para_vencimento'] ?? 0) < 0)
                  <div style="color:#A1A1AA;font-size:11px;">{{ abs($c['dias_para_vencimento']) }} dias atrás</div>
                @endif
              </td>
              <td style="padding:10px;border-bottom:1px solid #27272A;color:#D4D4D8;font-size:13px;">{{ $c['taxa_reajuste'] === 'IGPM' ? 'IGP-M' : $c['taxa_reajuste'] }}</td>
              <td style="padding:10px;border-bottom:1px solid #27272A;font-size:13px;text-align:right;white-space:nowrap;">
                <span style="color:#34D399;font-weight:700;">+{{ $brl($c['valor_estimado_reajuste']) }}</span>
                <div style="color:#71717A;font-size:11px;">~{{ $c['percentual_estimado'] }}%</div>
              </td>
            </tr>
            @endforeach
          </table>
        </div>
      </td>
    </tr>

    <!-- CTA -->
    @if($dashboardUrl)
    <tr>
      <td style="padding:22px 40px 4px;background:#000000;text-align:center;">
        <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#00F5FF;color:#000000;font-size:14px;font-weight:800;text-decoration:none;padding:12px 28px;border-radius:10px;">
          Abrir dashboard de reajustes
        </a>
      </td>
    </tr>
    @endif

    <!-- Rodapé -->
    <tr>
      <td style="padding:22px 40px 28px;background:#000000;">
        <p style="margin:0;color:#52525B;font-size:11px;line-height:1.5;">
          E-mail automático do Minutor — enviado no 1º dia útil do mês. O reajuste <b style="color:#71717A;">não é aplicado automaticamente</b>:
          confira o índice e o período, e aplique manualmente no dashboard.
        </p>
      </td>
    </tr>

  </table>
</div>
</body>
</html>
