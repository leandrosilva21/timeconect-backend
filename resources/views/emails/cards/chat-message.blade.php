<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>{{ $cardCode }} — Nova mensagem</title>
</head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.22em;color:#22D3EE;font-weight:800;text-transform:uppercase;">{{ $eyebrow }}</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">Você tem uma nova mensagem</h1>
        <p style="margin:0;color:#D4D4D8;font-size:14px;line-height:1.55;">
          {{ $cardType === 'contract_request' ? 'Requisição' : 'Projeto' }}
          <b style="color:#FFFFFF;">{{ $cardCode }}</b> — {{ $cardTitle }}
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#15151A;border-left:4px solid #22D3EE;padding:18px 20px;border-radius:0 12px 12px 0;border-top:1px solid #3F3F46;border-right:1px solid #3F3F46;border-bottom:1px solid #3F3F46;">
          <div style="font-size:12px;color:#FFFFFF;font-weight:700;margin-bottom:8px;">{{ $authorName }} · <span style="color:#D4D4D8;font-weight:600;">{{ $authorRole }}</span></div>
          <div style="color:#FFFFFF;font-size:14px;line-height:1.6;">{!! nl2br(e($messageExcerpt)) !!}</div>
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $openUrl }}" style="display:inline-block;background:#22D3EE;color:#000000;text-decoration:none;font-weight:800;font-size:13px;padding:13px 26px;border-radius:8px;">Abrir conversa</a>
        <a href="{{ $cardUrl }}" style="display:inline-block;color:#22D3EE;text-decoration:none;font-weight:700;font-size:13px;padding:13px 18px;margin-left:6px;">Ver {{ $cardType === 'contract_request' ? 'requisição' : 'projeto' }}</a>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#D4D4D8;font-size:12px;line-height:1.7;border-top:1px solid #3F3F46;">
        Olá, {{ $recipientName }}. Você está recebendo este email porque é um dos envolvidos
        deste {{ $cardType === 'contract_request' ? 'card de requisição' : 'card de projeto' }}.
        @if($cardType === 'project')
          Este é um chat interno — clientes não têm acesso a estas mensagens.
        @endif
        <br><br>
        <span style="color:#71717A;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
