{{-- Card header com logo ERPServ branco + Minutor. Compartilhado entre todos templates de card. --}}
<tr>
  <td align="left" style="padding:36px 40px 28px;background:#000000;border-bottom:1px solid rgba(255,255,255,0.06);">

    {{-- ERPServ — logo centralizado --}}
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
          <div style="font-size:26px;font-weight:700;letter-spacing:-0.02em;color:#FFFFFF;line-height:1.05;font-family:'Segoe UI',Arial,sans-serif;">Minutor</div>
          <div style="margin-top:4px;font-size:13px;color:rgba(255,255,255,0.38);font-weight:400;font-family:'Segoe UI',Arial,sans-serif;">Controle de horas e contratos em um só lugar</div>
        </td>
      </tr>
    </table>

  </td>
</tr>
