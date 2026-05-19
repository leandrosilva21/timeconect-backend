<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>{{ $codigo }} — Início Autorizado</title></head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.22em;color:#22D3EE;font-weight:800;text-transform:uppercase;">Movimentação no Kanban</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">
          Contrato aguardando início do projeto
        </h1>
        <p style="margin:0;color:#D4D4D8;font-size:14px;line-height:1.55;">
          @if($codigo !== '—')Contrato <b style="color:#FFFFFF;">{{ $codigo }}</b>@if($projeto !== '—') — {{ $projeto }}@endif @else{{ $projeto !== '—' ? $projeto : 'Detalhes no kanban' }}@endif
        </p>
        <p style="margin:4px 0 0;color:#D4D4D8;font-size:14px;line-height:1.55;">
          Cliente: <b style="color:#FFFFFF;">{{ $cliente }}</b>
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:22px;">
          <div style="font-size:10px;letter-spacing:.24em;color:#C4B5FD;font-weight:800;text-transform:uppercase;margin-bottom:14px;">Status</div>
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
              <td valign="middle" align="center">
                <div style="display:inline-block;background-color:#22D3EE;color:#000000;font-size:11px;font-weight:800;padding:8px 16px;border-radius:999px;letter-spacing:.06em;">INÍCIO AUTORIZADO</div>
              </td>
            </tr>
          </table>
          <p style="margin:18px 0 0;color:#D4D4D8;font-size:14px;line-height:1.6;">
            O contrato passou pela triagem administrativa e está com início autorizado.
            Próximo passo: alocar coordenador e gerar o projeto. Acompanhe pelo Kanban de Contratos.
          </p>
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $cardUrl }}" style="display:inline-block;background:#FBBF24;color:#000000;text-decoration:none;font-weight:800;font-size:13px;padding:13px 26px;border-radius:8px;">Abrir Kanban de Contratos</a>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#D4D4D8;font-size:12px;line-height:1.7;border-top:1px solid #3F3F46;">
        Olá, {{ $recipientName }}. Você está recebendo este e-mail como executivo da conta —
        contratos do seu cliente são comunicados a cada movimentação relevante.
        <br><br>
        <span style="color:#71717A;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
