<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Bem-vindo(a) ao Minutor</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
    body { margin: 0 !important; padding: 0 !important; background-color: #0A0A0B; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    @media only screen and (max-width: 620px) {
      .wrapper { width: 100% !important; }
      .card { border-radius: 12px !important; margin: 0 12px !important; }
      .pd-main { padding: 28px 20px !important; }
      .pd-header { padding: 32px 20px 24px !important; }
      .logo-text { font-size: 22px !important; }
      .h1 { font-size: 22px !important; }
      .btn-td { padding: 28px 20px !important; }
      .hide-mobile { display: none !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#0A0A0B;font-family:'Segoe UI',Arial,sans-serif;">

  {{-- Outer wrapper --}}
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
    style="background-color:#0A0A0B;min-height:100vh;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        {{-- Card container --}}
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
          class="wrapper card"
          style="background-color:#161618;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.06);">

          {{-- ── HEADER ── --}}
          <tr>
            <td class="pd-header" align="center"
              style="padding:40px 40px 32px;background-color:#111113;border-bottom:1px solid rgba(255,255,255,0.06);">

              {{-- Logo Minutor inline com o nome --}}
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding-bottom:16px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:10px;">
                          <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzYiIGhlaWdodD0iMzYiIHZpZXdCb3g9IjAgMCAyOCAyOCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB4PSIyIiB5PSIxNS40IiB3aWR0aD0iNC4yIiBoZWlnaHQ9IjkiIHJ4PSIxLjYiIGZpbGw9IiMwMEY1RkYiLz48cmVjdCB4PSI5LjEiIHk9IjkuNCIgd2lkdGg9IjQuMiIgaGVpZ2h0PSIxNSIgcng9IjEuNiIgZmlsbD0iIzAwRjVGRiIvPjxyZWN0IHg9IjE2LjIiIHk9IjQiIHdpZHRoPSI0LjIiIGhlaWdodD0iMjAiIHJ4PSIxLjYiIGZpbGw9IiMwMEY1RkYiLz48cmVjdCB4PSIyMy4yIiB5PSIxMS42IiB3aWR0aD0iNC4yIiBoZWlnaHQ9IjEyIiByeD0iMS42IiBmaWxsPSIjMDBGNUZGIi8+PC9zdmc+" alt="Minutor" width="32" height="32" style="display:block;width:32px;height:32px;" />
                        </td>
                        <td style="vertical-align:middle;">
                          <span class="logo-text"
                            style="font-size:26px;font-weight:700;letter-spacing:-0.5px;color:#FFFFFF;font-family:'Segoe UI',Arial,sans-serif;">
                            Minutor
                          </span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td align="center">
                    <span style="font-size:12px;color:#71717A;letter-spacing:0.5px;text-transform:uppercase;
                      font-family:'Segoe UI',Arial,sans-serif;">
                      Gestão de Projetos e Serviços
                    </span>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          {{-- ── SAUDAÇÃO ── --}}
          <tr>
            <td class="pd-main" align="left" style="padding:36px 40px 0;">
              <h1 class="h1"
                style="margin:0 0 8px;font-size:24px;font-weight:700;color:#FFFFFF;
                  font-family:'Segoe UI',Arial,sans-serif;line-height:1.3;">
                Bem-vindo(a), {{ $user->name ?? 'usuário' }}!
              </h1>
              <p style="margin:0;font-size:15px;color:#A1A1AA;line-height:1.6;
                font-family:'Segoe UI',Arial,sans-serif;">
                Sua conta foi criada com sucesso. Você já pode acessar o sistema com as credenciais abaixo.
              </p>
            </td>
          </tr>

          {{-- ── CREDENCIAIS ── --}}
          @if(isset($temporaryPassword))
          <tr>
            <td style="padding:24px 40px 0;">

              {{-- Bloco de credenciais --}}
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#1C1C1F;border-radius:12px;border:1px solid rgba(0,245,255,0.12);">
                <tr>
                  <td style="padding:6px 20px 0;">
                    <span style="font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;
                      color:#00F5FF;font-family:'Segoe UI',Arial,sans-serif;">
                      Dados de acesso
                    </span>
                  </td>
                </tr>

                {{-- Email --}}
                <tr>
                  <td style="padding:12px 20px 0;">
                    <span style="font-size:11px;color:#71717A;font-family:'Segoe UI',Arial,sans-serif;
                      text-transform:uppercase;letter-spacing:0.5px;">E-mail</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:4px 20px 16px;">
                    <span style="font-size:14px;color:#FFFFFF;font-weight:500;
                      font-family:'Courier New',Courier,monospace;">
                      {{ $user->email }}
                    </span>
                  </td>
                </tr>

                {{-- Divisor --}}
                <tr>
                  <td style="padding:0 20px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                      <tr><td style="border-top:1px solid rgba(255,255,255,0.06);font-size:0;line-height:0;">&nbsp;</td></tr>
                    </table>
                  </td>
                </tr>

                {{-- Senha --}}
                <tr>
                  <td style="padding:16px 20px 0;">
                    <span style="font-size:11px;color:#71717A;font-family:'Segoe UI',Arial,sans-serif;
                      text-transform:uppercase;letter-spacing:0.5px;">Senha temporária</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:4px 20px 16px;">
                    <span style="font-size:16px;color:#00F5FF;font-weight:700;letter-spacing:1px;
                      font-family:'Courier New',Courier,monospace;">
                      {{ $temporaryPassword }}
                    </span>
                  </td>
                </tr>

              </table>

            </td>
          </tr>

          {{-- ── ALERTA ── --}}
          <tr>
            <td style="padding:16px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#1F1A0E;border-radius:8px;border:1px solid rgba(251,191,36,0.2);">
                <tr>
                  <td style="padding:12px 16px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:10px;">
                          <span style="font-size:16px;line-height:1;">⚠️</span>
                        </td>
                        <td>
                          <span style="font-size:13px;color:#FCD34D;font-family:'Segoe UI',Arial,sans-serif;line-height:1.5;">
                            <strong>Altere sua senha no primeiro acesso.</strong>
                            Esta é uma senha temporária por segurança.
                          </span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          @endif

          {{-- ── BENEFÍCIOS ── --}}
          <tr>
            <td style="padding:28px 40px 0;">
              <p style="margin:0 0 14px;font-size:13px;font-weight:600;color:#FFFFFF;
                font-family:'Segoe UI',Arial,sans-serif;text-transform:uppercase;letter-spacing:0.5px;">
                O que você pode fazer no Minutor
              </p>
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                @foreach([
                  'Gerenciar projetos e demandas',
                  'Controlar horas trabalhadas',
                  'Acompanhar consumo de contratos',
                  'Visualizar relatórios',
                  'Colaborar com a equipe',
                ] as $item)
                <tr>
                  <td style="padding:5px 0;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:10px;width:20px;">
                          <span style="display:inline-block;width:6px;height:6px;background-color:#00F5FF;
                            border-radius:50%;"></span>
                        </td>
                        <td>
                          <span style="font-size:14px;color:#A1A1AA;font-family:'Segoe UI',Arial,sans-serif;
                            line-height:1.5;">{{ $item }}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                @endforeach
              </table>
            </td>
          </tr>

          {{-- ── BOTÃO ── --}}
          <tr>
            <td class="btn-td" align="center" style="padding:32px 40px 36px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center"
                    style="border-radius:10px;background:linear-gradient(135deg,#3B82F6 0%,#8B5CF6 100%);">
                    <a href="{{ config('app.frontend_url', 'https://app.minutor.com.br') }}"
                      target="_blank"
                      style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:600;
                        color:#FFFFFF;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;
                        letter-spacing:0.2px;">
                      Acessar o Minutor &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

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
            <td align="center" style="padding:32px 40px 40px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding-bottom:6px;">
                    <span style="font-size:10px;color:rgba(255,255,255,0.3);text-transform:uppercase;
                      letter-spacing:1px;font-family:'Segoe UI',Arial,sans-serif;">Uma solução</span>
                  </td>
                </tr>
                <tr>
                  <td align="center">
                    <img src="data:image/png;base64,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" alt="ERPServ" width="160" height="80" style="display:inline-block;width:160px;height:80px;filter:brightness(0) invert(1);-webkit-filter:brightness(0) invert(1);" />
                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>
        {{-- /card --}}

        {{-- Espaço inferior --}}
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="wrapper">
          <tr>
            <td align="center" style="padding-top:20px;">
              <span style="font-size:11px;color:rgba(255,255,255,0.15);font-family:'Segoe UI',Arial,sans-serif;">
                Você está recebendo este e-mail porque uma conta foi criada em seu nome.
              </span>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

</body>
</html>
