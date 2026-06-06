<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light dark">
</head>
{{-- Continuação da conversa do fechamento: texto livre + assinatura (sem o template do fechamento). --}}
<body style="margin:0;padding:0;background-color:#FFFFFF;font-family:'Segoe UI',Arial,sans-serif;color:#1F2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="background-color:#FFFFFF;">
    <tr>
      <td align="left" style="padding:24px 28px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
          <tr>
            <td style="font-size:15px;line-height:1.6;color:#1F2937;white-space:pre-wrap;">{{ $bodyText }}</td>
          </tr>
          <tr>
            <td style="padding-top:18px;">
              <div style="border-top:1px solid #E5E7EB;padding-top:14px;font-size:13px;color:#4B5563;line-height:1.5;">
                Atenciosamente,<br>
                <strong style="color:#111827;">{{ $senderName }}</strong><br>
                ERPSERV Consultoria
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding-top:14px;font-size:11px;color:#9CA3AF;">Enviado via Time Conect</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
