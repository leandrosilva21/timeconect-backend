# Fechamento por e-mail — Fase 2 (receber respostas via Microsoft Graph)

> Objetivo: quando o consultor **responder** o e-mail de fechamento, a resposta aparece
> **dentro do Time Conect** (na conversa do fechamento), não só no Outlook.

## Como funciona (já implementado no Time Conect)

- O e-mail de fechamento sai com **From = `noreply@erpserv.com.br`** e **Reply-To = `noreply@erpserv.com.br`** (financeiro segue em **CC** para visibilidade).
- Quando o consultor clica **Responder**, a resposta cai na **caixa do `noreply`**.
- Um job do Time Conect (`fechamento:poll-inbox`, a cada 5 min) **lê** essa caixa via **Microsoft Graph**, filtra **só** as respostas de fechamento (casando `In-Reply-To`/`References` com o `Message-ID` que o Time Conect gerou) e grava na conversa. As demais mensagens do `noreply` (outros workflows) são **ignoradas**.
- **Sem credenciais do Graph, o job dorme** (não quebra nada). Ele "acende" assim que o TI entregar os 3 valores abaixo.

## O que o Time Conect precisa do TI (uma vez)

Registrar um **app no Azure AD (Entra ID)** com permissão de **aplicação** para ler **apenas** a caixa do `noreply`:

1. **Azure Portal → Entra ID → App registrations → New registration**
   - Nome sugerido: `Time Conect — Leitura Fechamento (noreply)`
   - Single tenant. Não precisa Redirect URI (é app-only, sem login de usuário).

2. **API permissions → Add a permission → Microsoft Graph → Application permissions**
   - Adicionar **`Mail.Read`** (Application).
   - **Grant admin consent** para o tenant.

3. **Certificates & secrets → New client secret**
   - Gerar um secret e copiar o **valor** (só aparece uma vez).

4. **(Recomendado — escopo mínimo) Application Access Policy** no Exchange Online, pra esse app **só** conseguir ler a caixa do `noreply` (e nenhuma outra). Via Exchange Online PowerShell:
   ```powershell
   # cria um grupo de e-mail com só a caixa do noreply (se ainda não houver)
   New-DistributionGroup -Name "Time Conect-Graph-Scope" -Type Security -Members noreply@erpserv.com.br

   # restringe o app (use o Application/Client ID do passo 1) a esse grupo
   New-ApplicationAccessPolicy -AppId <CLIENT_ID> `
     -PolicyScopeGroupId Time Conect-Graph-Scope@erpserv.com.br `
     -AccessRight RestrictAccess `
     -Description "Time Conect só lê a caixa do noreply (fechamento)"

   # validar
   Test-ApplicationAccessPolicy -Identity noreply@erpserv.com.br -AppId <CLIENT_ID>   # => Granted
   Test-ApplicationAccessPolicy -Identity financeiro@erpserv.com.br -AppId <CLIENT_ID> # => Denied
   ```

## O que entregar de volta (3 + 1 valores)

| Variável (.env / docker) | Onde achar |
|---|---|
| `GRAPH_TENANT_ID`     | Entra ID → Overview → **Directory (tenant) ID** |
| `GRAPH_CLIENT_ID`     | App registration → Overview → **Application (client) ID** |
| `GRAPH_CLIENT_SECRET` | Certificates & secrets → **valor** do secret |
| `GRAPH_MAILBOX`       | A caixa lida — `noreply@erpserv.com.br` (default já é o `MAIL_FROM_ADDRESS`) |

## Ativação (lado Time Conect, depois que o TI entregar)

1. Pôr as 4 variáveis no `.env` de produção (e no compose do backend/scheduler).
2. `docker compose up -d` (recria com o env) — o scheduler já roda `fechamento:poll-inbox` a cada 5 min.
3. Teste manual: `docker exec timeconect-backend php artisan fechamento:poll-inbox`
   - Sem credenciais: "Microsoft Graph não configurado — polling pulado".
   - Com credenciais: "Respostas importadas: N | ignoradas: M".

## Segurança / privacidade

- Permissão é **Mail.Read** (só leitura), e a Application Access Policy limita ao `noreply` — o app **não** lê a caixa de ninguém mais.
- O Time Conect só persiste respostas que casam com uma thread de fechamento; o resto do `noreply` é ignorado e não é gravado.
