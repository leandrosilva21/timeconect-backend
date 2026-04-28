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

              {{-- Logo Minutor (4 barras) --}}
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding-bottom:14px;">
                    <img src="data:image/png;base64,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" alt="Minutor" width="80" height="40" style="display:inline-block;width:80px;height:40px;" />
                  </td>
                </tr>
                <tr>
                  <td align="center">
                    <span class="logo-text"
                      style="font-size:26px;font-weight:700;letter-spacing:-0.5px;color:#FFFFFF;font-family:'Segoe UI',Arial,sans-serif;">
                      Minutor
                    </span>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding-top:4px;">
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
            <td align="center" style="padding:24px 40px 32px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding-bottom:6px;">
                    <span style="font-size:10px;color:rgba(255,255,255,0.3);text-transform:uppercase;
                      letter-spacing:1px;font-family:'Segoe UI',Arial,sans-serif;">Uma solução</span>
                  </td>
                </tr>
                <tr>
                  <td align="center">
                    <img src="data:image/png;base64,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" alt="ERPServ" width="80" height="40"
                      style="display:inline-block;width:80px;height:40px;opacity:0.7;" />
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
