<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Redefinição de Senha — Minutor</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
    body { margin: 0 !important; padding: 0 !important; background-color: #000000; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    @media only screen and (max-width: 620px) {
      .wrapper { width: 100% !important; }
      .card { border-radius: 12px !important; margin: 0 12px !important; }
      .pd-main { padding: 28px 20px !important; }
      .pd-header { padding: 32px 20px 24px !important; }
      .btn-td { padding: 28px 20px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#000000;font-family:'Segoe UI',Arial,sans-serif;">

  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
    style="background-color:#000000;min-height:100vh;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
          class="wrapper card"
          style="background-color:#000000;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.06);">

          {{-- ── HEADER ── --}}
          <tr>
            <td class="pd-header" align="left"
              style="padding:36px 40px 28px;background-color:#000000;border-bottom:1px solid rgba(255,255,255,0.06);">

              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
                <tr>
                  <td align="center">
                    <a href="https://erpserv.com.br" target="_blank" style="text-decoration:none;">
                      <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('logo-erpserv-white.png'))) }}"
                        alt="ERPServ Consultoria"
                        width="140" height="auto"
                        style="display:inline-block;width:140px;height:auto;opacity:0.85;" />
                    </a>
                  </td>
                </tr>
              </table>

              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="vertical-align:middle;padding-right:14px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td align="center" style="width:36px;height:36px;border-radius:9px;background:rgba(0,212,232,0.07);border:1px solid rgba(0,212,232,0.12);vertical-align:middle;">
                          <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTkiIGhlaWdodD0iMTkiIHZpZXdCb3g9IjAgMCAyOCAyOCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB4PSIyIiB5PSIxNS40IiB3aWR0aD0iNC4yIiBoZWlnaHQ9IjkiIHJ4PSIxLjYiIGZpbGw9IiMwMEY1RkYiLz48cmVjdCB4PSI5LjEiIHk9IjkuNCIgd2lkdGg9IjQuMiIgaGVpZ2h0PSIxNSIgcng9IjEuNiIgZmlsbD0iIzAwRjVGRiIvPjxyZWN0IHg9IjE2LjIiIHk9IjQiIHdpZHRoPSI0LjIiIGhlaWdodD0iMjAiIHJ4PSIxLjYiIGZpbGw9IiMwMEY1RkYiLz48cmVjdCB4PSIyMy4yIiB5PSIxMS42IiB3aWR0aD0iNC4yIiBoZWlnaHQ9IjEyIiByeD0iMS42IiBmaWxsPSIjMDBGNUZGIi8+PC9zdmc+" alt="" width="19" height="19" style="display:inline-block;width:19px;height:19px;" />
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td style="vertical-align:middle;">
                    <div style="font-size:26px;font-weight:700;letter-spacing:-0.02em;color:#FFFFFF;line-height:1.05;font-family:'Segoe UI',Arial,sans-serif;">
                      Minutor
                    </div>
                    <div style="margin-top:4px;font-size:13px;color:rgba(255,255,255,0.38);font-weight:400;font-family:'Segoe UI',Arial,sans-serif;">
                      Controle de horas e contratos em um só lugar
                    </div>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          {{-- ── SAUDAÇÃO ── --}}
          <tr>
            <td class="pd-main" align="left" style="padding:36px 40px 0;">
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#FFFFFF;
                font-family:'Segoe UI',Arial,sans-serif;line-height:1.3;">
                Redefinição de Senha
              </h1>
              <p style="margin:0;font-size:15px;color:#A1A1AA;line-height:1.6;
                font-family:'Segoe UI',Arial,sans-serif;">
                Olá, <strong style="color:#FFFFFF;">{{ $user->name ?? 'Usuário' }}</strong>!
                Recebemos uma solicitação para redefinir a senha da sua conta no Minutor.
              </p>
            </td>
          </tr>

          {{-- ── BOTÃO REDEFINIR ── --}}
          <tr>
            <td class="btn-td" align="center" style="padding:32px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center"
                    style="border-radius:10px;background:linear-gradient(135deg,#3B82F6 0%,#8B5CF6 100%);">
                    <a href="{{ $resetUrl }}"
                      target="_blank"
                      style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:600;
                        color:#FFFFFF;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;
                        letter-spacing:0.2px;">
                      Redefinir Senha &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── ALERTA ── --}}
          <tr>
            <td style="padding:24px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#1F1A0E;border-radius:8px;border:1px solid rgba(251,191,36,0.2);">
                <tr>
                  <td style="padding:14px 16px;">
                    <span style="font-size:13px;color:#FCD34D;font-family:'Segoe UI',Arial,sans-serif;
                      font-weight:600;display:block;margin-bottom:6px;">⚠️ IMPORTANTE:</span>
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                      @foreach([
                        'Este link expira em <strong>' . ($validMinutes ?? 60) . ' minutos</strong>',
                        'Se você não solicitou esta redefinição, ignore este e-mail',
                        'Sua senha atual continuará funcionando até você criar uma nova',
                      ] as $aviso)
                      <tr>
                        <td style="padding:3px 0;">
                          <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="vertical-align:top;padding-right:8px;width:14px;">
                                <span style="font-size:13px;color:#FCD34D;">•</span>
                              </td>
                              <td>
                                <span style="font-size:13px;color:#D4A017;font-family:'Segoe UI',Arial,sans-serif;line-height:1.5;">
                                  {!! $aviso !!}
                                </span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                      @endforeach
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── LINK ALTERNATIVO ── --}}
          <tr>
            <td style="padding:20px 40px 0;">
              <p style="margin:0;font-size:12px;color:#52525B;font-family:'Segoe UI',Arial,sans-serif;line-height:1.6;">
                Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                <a href="{{ $resetUrl }}" style="color:#00F5FF;word-break:break-all;">{{ $resetUrl }}</a>
              </p>
            </td>
          </tr>

          <tr><td style="padding-bottom:36px;"></td></tr>

          {{-- ── DIVISOR ── --}}
          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr><td style="border-top:1px solid rgba(255,255,255,0.06);font-size:0;line-height:0;">&nbsp;</td></tr>
              </table>
            </td>
          </tr>

          {{-- ── RODAPÉ ── --}}
          <tr>
            <td align="center" style="padding:24px 40px 32px;">
              <span style="font-size:11px;color:rgba(255,255,255,0.18);font-family:'Segoe UI',Arial,sans-serif;letter-spacing:0.02em;">
                &copy; {{ date('Y') }} ERPServ Consultoria &middot; Todos os direitos reservados
              </span>
            </td>
          </tr>

        </table>

        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="wrapper">
          <tr>
            <td align="center" style="padding-top:20px;">
              <span style="font-size:11px;color:rgba(255,255,255,0.15);font-family:'Segoe UI',Arial,sans-serif;">
                Você está recebendo este e-mail porque uma redefinição de senha foi solicitada para sua conta.
              </span>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

</body>
</html>
