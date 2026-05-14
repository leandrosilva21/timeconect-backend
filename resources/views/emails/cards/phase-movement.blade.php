<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>{{ $cardCode }} — Movimentação</title></head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.06);">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.2em;color:#F59E0B;font-weight:700;text-transform:uppercase;">Movimentação de fase</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:22px;line-height:1.3;font-weight:700;">
          {{ $cardType === 'contract_request' ? 'A requisição' : 'O projeto' }} avançou de fase
        </h1>
        <p style="margin:0;color:#A1A1AA;font-size:14px;line-height:1.55;">
          {{ $cardType === 'contract_request' ? 'Requisição' : 'Projeto' }}
          <b style="color:#FFFFFF;">{{ $cardCode }}</b> — {{ $cardTitle }}
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#18181B;border:1px solid #27272A;border-radius:10px;padding:20px;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
              <td valign="middle" align="center" style="width:42%;">
                <div style="display:inline-block;background-color:#27272A;color:#E4E4E7;font-size:11px;font-weight:700;padding:7px 14px;border-radius:999px;letter-spacing:.06em;">{{ $fromColumn }}</div>
              </td>
              <td valign="middle" align="center" style="width:16%;font-size:20px;color:#A1A1AA;">→</td>
              <td valign="middle" align="center" style="width:42%;">
                <div style="display:inline-block;background-color:#4F46E5;color:#FFFFFF;font-size:11px;font-weight:700;padding:7px 14px;border-radius:999px;letter-spacing:.06em;">{{ $toColumn }}</div>
              </td>
            </tr>
          </table>

          <div style="margin-top:18px;font-size:13px;line-height:1.7;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="color:#A1A1AA;width:34%;padding:4px 0;">Movido por</td>
                <td style="color:#FAFAFA;padding:4px 0;font-weight:600;">{{ $movedByName }} · <span style="color:#D4D4D8;font-weight:500;">{{ $movedByRole }}</span></td>
              </tr>
              <tr>
                <td style="color:#A1A1AA;padding:4px 0;">Quando</td>
                <td style="color:#FAFAFA;padding:4px 0;">{{ now()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
              </tr>
              @if($note)
              <tr>
                <td style="color:#A1A1AA;padding:4px 0;vertical-align:top;">Observação</td>
                <td style="color:#FAFAFA;padding:4px 0;">{{ $note }}</td>
              </tr>
              @endif
            </table>
          </div>
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $cardUrl }}" style="display:inline-block;background:#F59E0B;color:#0B0E13;text-decoration:none;font-weight:700;font-size:13px;padding:12px 24px;border-radius:8px;">Ver {{ $cardType === 'contract_request' ? 'requisição' : 'projeto' }}</a>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#71717A;font-size:11px;line-height:1.65;border-top:1px solid rgba(255,255,255,0.06);">
        Olá, {{ $recipientName }}. Você está recebendo este email porque é um dos envolvidos
        deste {{ $cardType === 'contract_request' ? 'card de requisição' : 'card de projeto' }}.
        Movimentações de fase são notificadas para todos os envolvidos (incluindo o cliente).
        <br><br>
        <span style="color:#52525B;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
