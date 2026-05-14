<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>{{ $cardCode }} — Nova mensagem</title>
</head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.06);">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.2em;color:#00F5FF;font-weight:700;text-transform:uppercase;">{{ $eyebrow }}</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:22px;line-height:1.3;font-weight:700;">Você tem uma nova mensagem</h1>
        <p style="margin:0;color:#A1A1AA;font-size:14px;line-height:1.55;">
          {{ $cardType === 'contract_request' ? 'Requisição' : 'Projeto' }}
          <b style="color:#FFFFFF;">{{ $cardCode }}</b> — {{ $cardTitle }}
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#18181B;border-left:3px solid #00F5FF;padding:18px 20px;border-radius:0 10px 10px 0;border-top:1px solid #27272A;border-right:1px solid #27272A;border-bottom:1px solid #27272A;">
          <div style="font-size:12px;color:#E4E4E7;font-weight:700;margin-bottom:8px;">{{ $authorName }} · <span style="color:#A1A1AA;font-weight:600;">{{ $authorRole }}</span></div>
          <div style="color:#FAFAFA;font-size:14px;line-height:1.6;">{!! nl2br(e($messageExcerpt)) !!}</div>
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $openUrl }}" style="display:inline-block;background:#00F5FF;color:#0B0E13;text-decoration:none;font-weight:700;font-size:13px;padding:12px 24px;border-radius:8px;">Abrir conversa</a>
        <a href="{{ $cardUrl }}" style="display:inline-block;color:#00F5FF;text-decoration:none;font-weight:600;font-size:13px;padding:12px 18px;margin-left:6px;">Ver {{ $cardType === 'contract_request' ? 'requisição' : 'projeto' }}</a>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#71717A;font-size:11px;line-height:1.65;border-top:1px solid rgba(255,255,255,0.06);">
        Olá, {{ $recipientName }}. Você está recebendo este email porque é um dos envolvidos
        deste {{ $cardType === 'contract_request' ? 'card de requisição' : 'card de projeto' }}.
        @if($cardType === 'project')
          Este é um chat interno — clientes não têm acesso a estas mensagens.
        @endif
        <br><br>
        <span style="color:#52525B;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
