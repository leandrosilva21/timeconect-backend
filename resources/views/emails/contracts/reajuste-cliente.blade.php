<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Comunicado de reajuste contratual</title></head>
@php
    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $indiceLabel = $indice === 'IGPM' ? 'IGP-M' : $indice;
@endphp
<body style="margin:0;padding:0;background-color:#F4F5F7;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#F4F5F7">
  <tr>
    <td align="center" bgcolor="#F4F5F7" style="padding:32px 16px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
        bgcolor="#FFFFFF" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;overflow:hidden;border:1px solid #E5E7EB;">

        <!-- Cabeçalho (faixa escura com o logo branco — robusto em light e dark mode) -->
        <tr>
          <td align="left" bgcolor="#0F172A" style="padding:28px 40px 24px;background-color:#0F172A;">
            <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('logo-erpserv-white.png'))) }}"
              alt="ERPSERV" width="150" height="auto" style="display:block;width:150px;height:auto;" />
            <div style="font-size:11px;letter-spacing:.18em;color:#67E8F9;font-weight:700;text-transform:uppercase;margin-top:12px;">Comunicado de reajuste contratual</div>
          </td>
        </tr>

        <!-- Corpo -->
        <tr>
          <td align="left" bgcolor="#FFFFFF" style="padding:30px 40px 8px;">
            <p style="margin:0 0 14px;color:#0F172A;font-size:15px;line-height:1.6;">Prezado(a) <b>{{ $cliente }}</b>,</p>
            <p style="margin:0 0 14px;color:#334155;font-size:14px;line-height:1.65;">
              Em conformidade com o seu contrato{{ $contrato ? ' ('.$contrato.')' : '' }}, informamos o <b>reajuste do valor contratado</b>,
              calculado pela variação acumulada do índice <b>{{ $indiceLabel }}</b>@if($periodoFormatado) no período de <b>{{ $periodoFormatado }}</b>@endif,
              correspondente a <b style="color:#0891B2;">+{{ number_format($percentual, 2, ',', '.') }}%</b>.
            </p>
          </td>
        </tr>

        <!-- Quadro de valores -->
        <tr>
          <td align="left" style="padding:6px 40px 0;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
              style="background-color:#F8FAFC;border:1px solid #E5E7EB;border-radius:12px;">
              <tr>
                <td style="padding:18px 22px;">
                  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                      <td style="color:#64748B;font-size:13px;padding:4px 0;">Valor anterior</td>
                      <td align="right" style="color:#64748B;font-size:14px;padding:4px 0;text-decoration:line-through;">{{ $brl($valorAnterior) }}</td>
                    </tr>
                    <tr>
                      <td style="color:#0F172A;font-size:14px;font-weight:700;padding:4px 0;">Novo valor</td>
                      <td align="right" style="color:#0891B2;font-size:20px;font-weight:800;padding:4px 0;">{{ $brl($valorNovo) }}</td>
                    </tr>
                    <tr><td colspan="2" style="border-top:1px solid #E5E7EB;padding-top:10px;"></td></tr>
                    <tr>
                      <td style="color:#64748B;font-size:13px;padding:4px 0;">Índice aplicado</td>
                      <td align="right" style="color:#0F172A;font-size:13px;font-weight:600;padding:4px 0;">{{ $indiceLabel }} ({{ number_format($percentual, 2, ',', '.') }}%)</td>
                    </tr>
                    @if($periodoFormatado)
                    <tr>
                      <td style="color:#64748B;font-size:13px;padding:4px 0;">Período de apuração</td>
                      <td align="right" style="color:#0F172A;font-size:13px;font-weight:600;padding:4px 0;">{{ $periodoFormatado }}</td>
                    </tr>
                    @endif
                    <tr>
                      <td style="color:#64748B;font-size:13px;padding:4px 0;">Vigência a partir de</td>
                      <td align="right" style="color:#0F172A;font-size:13px;font-weight:600;padding:4px 0;">{{ $vigencia }}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Fecho -->
        <tr>
          <td align="left" bgcolor="#FFFFFF" style="padding:22px 40px 6px;">
            <p style="margin:0 0 14px;color:#334155;font-size:14px;line-height:1.65;">
              O novo valor passa a vigorar a partir da data indicada. Permanecemos à disposição para quaisquer esclarecimentos.
            </p>
            <p style="margin:0;color:#334155;font-size:14px;line-height:1.65;">
              Atenciosamente,<br><b style="color:#0F172A;">Equipe ERPSERV</b>
            </p>
          </td>
        </tr>

        <!-- Rodapé -->
        <tr>
          <td align="center" bgcolor="#F8FAFC" style="padding:20px 40px;border-top:1px solid #E5E7EB;">
            <p style="margin:0;color:#94A3B8;font-size:11px;line-height:1.5;">
              ERPSERV · Consultoria de Sistemas — dúvidas: financeiro@erpserv.com.br
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
