<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Bem-vindo(a) ao Minutor — ERPServ Consultoria</title>
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
            <td class="pd-header" align="left"
              style="padding:36px 40px 28px;background-color:#111113;border-bottom:1px solid rgba(255,255,255,0.06);">

              {{-- ERPServ — logo centralizado (texto, compatível com todos clientes de e-mail) --}}
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
                <tr>
                  <td align="center">
                    <a href="https://erpserv.com.br" target="_blank" style="text-decoration:none;">
                      <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('logo-erpserv.png'))) }}"
                        alt="ERPServ Consultoria"
                        width="140" height="auto"
                        style="display:inline-block;width:140px;height:auto;border-radius:8px;background-color:#FFFFFF;padding:6px 12px;" />
                    </a>
                  </td>
                </tr>
              </table>

              {{-- Minutor — produto --}}
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

          {{-- ── BOTÃO ACESSAR ── --}}
          <tr>
            <td class="btn-td" align="center" style="padding:32px 40px 0;">
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

          {{-- ── AVISO DE ANEXO ── --}}
          @if(isset($user) && $user->type === 'consultor' && in_array($user->consultant_type, ['horista','banco_de_horas']))
          <tr>
            <td style="padding:20px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#16161A;border-radius:10px;border:1px solid rgba(0,245,255,0.10);">
                <tr>
                  <td style="padding:14px 20px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:12px;font-size:18px;line-height:1;">📎</td>
                        <td style="vertical-align:middle;">
                          <div style="font-size:13px;color:#A1A1AA;font-family:'Segoe UI',Arial,sans-serif;line-height:1.5;">
                            O <strong style="color:#FFFFFF;">Manual do Usuário</strong> está anexado a este e-mail.
                          </div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          @endif

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
