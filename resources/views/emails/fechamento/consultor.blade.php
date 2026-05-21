<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Fechamento — {{ $periodo }}</title>
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
      .h1 { font-size: 22px !important; }
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
                    <div style="font-size:26px;font-weight:700;letter-spacing:-0.02em;color:#FFFFFF;line-height:1.05;">Minutor</div>
                    <div style="margin-top:4px;font-size:13px;color:rgba(255,255,255,0.38);font-weight:400;">Controle de horas e contratos em um só lugar</div>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          {{-- ── KICKER + TÍTULO ── --}}
          <tr>
            <td class="pd-main" align="left" style="padding:36px 40px 0;">
              <div style="font-size:11px;letter-spacing:.22em;color:#22D3EE;font-weight:800;text-transform:uppercase;">Fechamento de Consultores</div>
              <h1 class="h1" style="margin:8px 0 4px;font-size:24px;font-weight:700;color:#FFFFFF;line-height:1.3;">
                Olá, {{ $consultantName }}.
              </h1>
              <p style="margin:8px 0 0;font-size:15px;color:#A1A1AA;line-height:1.6;">
                Segue em anexo o fechamento referente ao período de <b style="color:#FFFFFF;">{{ $periodo }}</b>.
              </p>
            </td>
          </tr>

          {{-- ── VALOR TOTAL ── --}}
          <tr>
            <td style="padding:24px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#1C1C1F;border-radius:12px;border:1px solid rgba(0,245,255,0.12);">
                <tr>
                  <td style="padding:18px 22px;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#00F5FF;">
                      Valor total do fechamento
                    </div>
                    <div style="margin-top:6px;font-size:26px;font-weight:800;color:#FFFFFF;letter-spacing:-0.01em;">
                      {{ $valorTotal }}
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── ANEXOS ── --}}
          <tr>
            <td style="padding:18px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#16161A;border-radius:10px;border:1px solid rgba(0,245,255,0.10);">
                <tr>
                  <td style="padding:14px 20px;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:12px;font-size:18px;line-height:1;">📎</td>
                        <td style="vertical-align:middle;">
                          <div style="font-size:13px;color:#A1A1AA;line-height:1.5;">
                            Os arquivos anexos (<strong style="color:#FFFFFF;">PDF</strong> e <strong style="color:#FFFFFF;">Excel</strong>)
                            contêm o detalhamento completo dos apontamentos considerados no período.
                          </div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── CORPO ── --}}
          <tr>
            <td style="padding:24px 40px 0;">
              <p style="margin:0;font-size:14px;color:#A1A1AA;line-height:1.65;">
                Em caso de dúvidas ou divergências, por gentileza entrar em contato.
              </p>
              <p style="margin:22px 0 0;font-size:14px;color:#D4D4D8;line-height:1.65;">
                Atenciosamente,<br>
                <b style="color:#FFFFFF;">{{ $senderName }}</b><br>
                <span style="color:#71717A;">ERPSERV Consultoria</span>
              </p>
            </td>
          </tr>

          <tr><td style="padding-bottom:24px;"></td></tr>

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
            <td align="left" style="padding:18px 40px 32px;">
              <div style="font-size:12px;color:#71717A;line-height:1.6;">
                Em cópia: <span style="color:#A1A1AA;">{{ $financeiroCc }}</span>
              </div>
              <div style="margin-top:10px;font-size:11px;color:rgba(255,255,255,0.18);letter-spacing:0.02em;">
                &copy; {{ date('Y') }} ERPServ Consultoria &middot; Todos os direitos reservados
              </div>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

</body>
</html>
