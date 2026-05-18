<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>{{ $reqCode }} — {{ $stage === 'created' ? 'Criada' : 'Movimentação' }}</title>
</head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    @include('emails.cards._partial-header')

    {{-- Eyebrow + headline --}}
    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        @if($stage === 'created')
          <div style="font-size:11px;letter-spacing:.22em;color:#22D3EE;font-weight:800;text-transform:uppercase;">Requisição criada com sucesso</div>
          <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">
            Recebemos sua requisição
          </h1>
        @else
          <div style="font-size:11px;letter-spacing:.22em;color:#FBBF24;font-weight:800;text-transform:uppercase;">Movimentação no kanban</div>
          <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">
            A requisição avançou de fase
          </h1>
        @endif
        <p style="margin:0;color:#D4D4D8;font-size:14px;line-height:1.55;">
          Requisição <b style="color:#FFFFFF;">{{ $reqCode }}</b> — {{ $reqTitle }}
          <br>
          <span style="color:#71717A;font-size:12px;">Cliente: {{ $customerName }}</span>
        </p>
      </td>
    </tr>

    {{-- Corpo principal --}}
    @if($stage === 'created')
      <tr>
        <td style="padding:18px 40px 0;background:#000000;">
          <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:22px;">
            <div style="color:#FFFFFF;font-size:14px;line-height:1.7;">
              Sua requisição foi registrada com sucesso e está no <b>Backlog</b> do pipeline.
              <br><br>
              Quando estiver pronta pra iniciar, <b style="color:#22D3EE;">arraste o card para a coluna "Novo Projeto"</b>
              no seu painel. A partir daí o time da <b style="color:#FFFFFF;">ERPServ</b> inicia o levantamento
              e segue com a análise técnica.
              <br><br>
              Você receberá um email a cada mudança de fase enquanto a requisição estiver ativa,
              até virar contrato/projeto.
            </div>
          </div>
        </td>
      </tr>
    @else
      <tr>
        <td style="padding:18px 40px 0;background:#000000;">
          <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:22px;">
            <div style="font-size:10px;letter-spacing:.24em;color:#C4B5FD;font-weight:800;text-transform:uppercase;margin-bottom:14px;">Movimentação</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td valign="middle" align="center" style="width:42%;">
                  <div style="display:inline-block;background-color:#52525B;color:#FAFAFA;font-size:11px;font-weight:800;padding:8px 16px;border-radius:999px;letter-spacing:.06em;">{{ $fromColumnLabel ?? '—' }}</div>
                </td>
                <td valign="middle" align="center" style="width:16%;font-size:22px;color:#D4D4D8;font-weight:700;">→</td>
                <td valign="middle" align="center" style="width:42%;">
                  <div style="display:inline-block;background-color:#6366F1;color:#FFFFFF;font-size:11px;font-weight:800;padding:8px 16px;border-radius:999px;letter-spacing:.06em;">{{ $toColumnLabel }}</div>
                </td>
              </tr>
            </table>
            <div style="margin-top:14px;font-size:13px;color:#D4D4D8;line-height:1.7;">
              @if($toColumnLabel === 'Novo Projeto')
                A requisição entrou em <b style="color:#22D3EE;">"Novo Projeto"</b> — a ERPServ inicia agora
                o levantamento e a análise técnica. Acompanhe os próximos avanços por este canal.
              @else
                Acompanhe o avanço da requisição pelo painel. Quando estiver pronta pra iniciar,
                arraste o card pra coluna <b style="color:#22D3EE;">"Novo Projeto"</b> — a ERPServ inicia o levantamento.
              @endif
            </div>
          </div>
        </td>
      </tr>
    @endif

    {{-- CTA --}}
    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $cardUrl }}" style="display:inline-block;background:{{ $stage === 'created' ? '#22D3EE' : '#FBBF24' }};color:#000000;text-decoration:none;font-weight:800;font-size:13px;padding:13px 26px;border-radius:8px;">Abrir requisição</a>
      </td>
    </tr>

    {{-- Footer --}}
    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#D4D4D8;font-size:12px;line-height:1.7;border-top:1px solid #3F3F46;">
        Olá, {{ $recipientName }} <span style="color:#71717A;">({{ $recipientRole }})</span>.
        Você está recebendo este email porque está vinculado a esta requisição.
        Movimentações de fase são notificadas para o solicitante, executivo da conta e acompanhantes,
        até a requisição virar contrato/projeto.
        <br><br>
        <span style="color:#71717A;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
