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
                  <td align="center">
                    <img src="data:image/png;base64,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" alt="ERPServ" width="200" height="102" style="display:inline-block;width:200px;height:102px;filter:brightness(0) invert(1);-webkit-filter:brightness(0) invert(1);" />
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
