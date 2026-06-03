# FASE 11 — Runbook de Deploy em Produção

> **Status:** preparado na Replica. Aguarda aprovação explícita pra disparar.
>
> **Princípio inegociável:** nada quebra em prod. Dual-write + reader-shim
> permitem cada PR ser deployado isolado e revertido sem perda de dados.
>
> **Branching:** TODO PR off `prod/main` (Leonardo-almd) — nunca de `main` (dev2).

---

## Visão geral — 7 PRs em sequência

| # | Escopo | Tipo | Risco | Pode reverter? |
|---|---|---|---|---|
| 1 | 11.1 Fundação (migrations + service + rotas + integrity-check) | BE | Baixo (zero efeito visível) | Sim, drop tabelas novas |
| 2 | 11.2 Dual-write USER.avatar + EXPENSE.receipt + TIMESHEET.attachment | BE | Médio | Sim, remover try-catch e helpers |
| 3 | 11.2 Dual-write HOUR_CONTRIBUTION + STAGE_ACTIVITY_EVENT + 3 chats | BE | Médio | Sim |
| 4 | 11.2 Dual-write PROJECT + CONTRACT + FECHAMENTO_NOTA | BE | Médio-alto | Sim |
| 5 | 11.2.FE Hook + componentes shared + 3 view modals + helper unificado | FE | Baixo | Sim |
| 6 | 11.5 Observabilidade (controller + comando + página admin) | BE + FE | Baixo (read-only) | Sim |
| 7 | 11.4 Deprecate legado | BE | **Alto, irreversível** | **Não** — só após semanas observando |

**Não recomendado deploy junto:** PRs 2-3-4 devem ir em dias diferentes (≥24h cada) pra dar tempo de detectar regressão isolada por módulo.

---

## Pré-requisitos antes de tocar prod

1. ✅ Backend BE imagem GHCR build OK do branch testado
2. ✅ Replica rodou `attachments:backfill --all` sem `errors > 0`
3. ✅ Replica rodou `attachments:legacy-drop-preview` — anotar baseline (esperado: alguns "missing" na Replica por arquivos físicos perdidos, mas prod **não** deve ter)
4. ✅ Aprovação explícita do user pra disparar
5. Janela de manutenção: NÃO precisa (todos os PRs são compatíveis backward)

---

## PR 1 — Fundação (11.1)

**Conteúdo:**
- `database/migrations/2026_05_29_000001_create_attachments_table.php`
- `database/migrations/2026_05_29_000002_create_attachment_events_table.php`
- `app/Models/Attachment.php` + `app/Models/AttachmentEvent.php`
- `app/Attachments/AttachableEntitiesRegistry.php`
- `app/Attachments/AttachmentService.php` + `IntegrityReport.php`
- `app/Attachments/Storage/StorageProvider.php` + `LocalStorageProvider.php`
- `app/Attachments/Exceptions/*` (6 classes)
- `app/Providers/AttachmentsServiceProvider.php` + 1 linha em `bootstrap/providers.php`
- `app/Http/Controllers/AttachmentController.php`
- 7 rotas em `routes/api.php` (sob auth:sanctum)
- `app/Console/Commands/AttachmentsIntegrityCheck.php`
- `routes/console.php` — schedule daily 03:00

**Deploy:**
```bash
# 1. Branch
git -C ~/PROJETOS/Minutor-desenvolvimento/Backend fetch prod main
git worktree add -b fase11/01-fundacao /tmp/be-f11-01 prod/main

# 2. Copiar arquivos da Replica (cirurgicamente; nenhum arquivo legado tocado)
cp -r ~/PROJETOS/Minutor-Replica/Backend/app/Attachments /tmp/be-f11-01/app/Attachments
cp ~/PROJETOS/Minutor-Replica/Backend/app/Models/Attachment.php /tmp/be-f11-01/app/Models/
cp ~/PROJETOS/Minutor-Replica/Backend/app/Models/AttachmentEvent.php /tmp/be-f11-01/app/Models/
cp ~/PROJETOS/Minutor-Replica/Backend/app/Http/Controllers/AttachmentController.php /tmp/be-f11-01/app/Http/Controllers/
cp ~/PROJETOS/Minutor-Replica/Backend/app/Providers/AttachmentsServiceProvider.php /tmp/be-f11-01/app/Providers/
cp ~/PROJETOS/Minutor-Replica/Backend/database/migrations/2026_05_29_*.php /tmp/be-f11-01/database/migrations/
cp ~/PROJETOS/Minutor-Replica/Backend/app/Console/Commands/AttachmentsIntegrityCheck.php /tmp/be-f11-01/app/Console/Commands/

# 3. Aplicar diff cirúrgico em routes/api.php + bootstrap/providers.php + routes/console.php
#    (editor: adicionar SÓ as linhas novas; sem tocar nas outras edições do prod)

# 4. Commit + push + PR
cd /tmp/be-f11-01
git add -A && git commit -m "feat(attachments FASE 11.1): fundação polimórfica + service + integrity-check"
git push prod fase11/01-fundacao
gh pr create --repo Leonardo-almd/minutor-backend --base main --head Leonardo-almd:fase11/01-fundacao \
  --title "feat(attachments): FASE 11.1 — fundação polimórfica" \
  --body "Migrations + Service + Registry + Storage abstrato + rotas REST + integrity-check diário. Sem efeito em módulos existentes."

# 5. Aguardar GHA build → pull no VPS:
ssh minutor-prod 'cd /opt/minutor && docker compose pull backend && docker compose up -d --no-deps backend queue-worker scheduler'

# 6. Validar:
ssh minutor-prod 'cd /opt/minutor && docker compose exec -T backend php artisan tinker --execute="
echo \"Registry: \".implode(\",\", \App\Attachments\AttachableEntitiesRegistry::knownTypes()).PHP_EOL;
echo \"Service: \".get_class(app(\App\Attachments\AttachmentService::class)).PHP_EOL;
echo \"Attachments live: \".\App\Models\Attachment::count().PHP_EOL;
"'
```

**Critério de sucesso:** 
- ✅ Migrations rodaram (tabelas `attachments`, `attachment_events` criadas no prod DB)
- ✅ `route:list --path=attachments` mostra 7 rotas
- ✅ `php artisan attachments:integrity-check` retorna 0 anexos (vazio = OK)

**Rollback:** dropar 2 tabelas; reverter PR. Nenhum impacto em outros módulos.

---

## PR 2 — Dual-write básicos (USER + EXPENSE + TIMESHEET)

**Conteúdo:**
- `app/Models/User.php` (uploadProfilePhoto + removeProfilePhoto helpers; trait `HasGlobalAttachments`)
- `app/Http/Controllers/UserController.php` (uploadProfilePhoto dual-write try/catch)
- `app/Http/Controllers/ExpenseController.php` (dual-write nos 4 callsites + 2 helpers privados; trait)
- `app/Models/Expense.php` (trait HasGlobalAttachments + reader-shim em getReceiptUrlAttribute)
- `app/Http/Controllers/TimesheetController.php` (dual-write nos 3 callsites + 2 helpers; destroy soft-delete)
- `app/Models/Timesheet.php` (trait + reader-shim em getAttachmentUrlAttribute)

**Deploy:** mesmo padrão PR 1. Após deploy:
```bash
# Smoke: criar despesa de teste com receipt no prod
ssh minutor-prod 'docker compose exec backend php artisan tinker --execute="
echo \"Expenses com receipt_path: \".\App\Models\Expense::whereNotNull(\"receipt_path\")->count().PHP_EOL;
echo \"Attachments EXPENSE.receipt: \".\App\Models\Attachment::where(\"entity_type\",\"EXPENSE\")->count().PHP_EOL;
"'
```

**Backfill imediato (idempotente):**
```bash
ssh minutor-prod 'docker compose exec backend php artisan attachments:backfill --module=user-avatar'
ssh minutor-prod 'docker compose exec backend php artisan attachments:backfill --module=expense-receipt'
ssh minutor-prod 'docker compose exec backend php artisan attachments:backfill --module=timesheet-attachment'
```

**Critério:** após backfill, `attachments:legacy-drop-preview` deve mostrar 0 missing nos 3 módulos. Monitorar 24h.

---

## PR 3 — Dual-write médios (HOUR_CONTRIBUTION + STAGE_ACTIVITY_EVENT + 3 chats)

**Conteúdo:**
- `app/Attachments/AttachableEntitiesRegistry.php` (atualizar HOUR_CONTRIBUTION MIME types pra alinhar com legado expandido)
- `app/Attachments/Concerns/DualWritesStageAttachment.php`
- `app/Attachments/Concerns/DualWritesMessageAttachments.php`
- `app/Http/Controllers/HourContributionController.php`
- `app/Models/HourContribution.php` (trait)
- `app/Http/Controllers/ProjectStageController.php`
- `app/Http/Controllers/StageDeliveryController.php`
- `app/Http/Controllers/ClientActivityController.php`
- `app/Http/Controllers/ProjectMessageController.php`
- `app/Http/Controllers/ContractMessageController.php`
- `app/Http/Controllers/ContractRequestMessageController.php`

**Após deploy:** backfill dos 5 módulos.

---

## PR 4 — Dual-write Project/Contract/FechamentoNota

**Conteúdo:**
- `app/Attachments/Concerns/DualWritesEntityAttachments.php` (com mapa pt→en de category)
- `app/Http/Controllers/ProjectController.php` (uploadAttachment + deleteAttachment)
- `app/Http/Controllers/ContractController.php` (idem)
- `app/Http/Controllers/FechamentoNotaController.php` (com helpers internos próprios)

**Pegadinhas:**
- `LocalStorageProvider.php` precisa estar com a heurística atualizada (PROJECTS/CONTRACTS em disco `local`, não public). Já corrigida na Replica — verificar que o arquivo deployado tem o fix.
- **PROJECT/CONTRACT** usam disco `local` privado, diferente dos outros.

---

## PR 5 — FE Layer

**Conteúdo:**
- `src/lib/attachments.ts`
- `src/hooks/use-attachments.ts`
- `src/components/attachments/*` (5 arquivos)
- 3 view modals migrados: expense-view-modal, timesheet-view-modal, AporteDetailModal
- 5 cópias unificadas: meu-painel, ExpensesScreen, expense-view-modal, approvals, ApprovalsScreen

**Deploy:**
```bash
# Branch off prod/main, copiar arquivos, push, PR, vercel --prod
```

**Critério:** TSC clean; smoke das 3 telas — abre modal de despesa/timesheet/aporte e ver que "Anexos adicionais" aparece (vazio é OK).

---

## PR 6 — Observabilidade

**Conteúdo:**
- `app/Http/Controllers/AttachmentsAnalyticsController.php`
- 3 rotas em `routes/api.php` (antes de `/attachments/{id}`)
- `app/Console/Commands/AttachmentsStats.php`
- `src/app/admin/attachments/page.tsx`

**Validar acesso em prod:** `https://app.minutor.com.br/admin/attachments` — admin vê painel; não-admin recebe negado.

---

## PR 7 — Deprecate legado (FASE 11.4) — **APROVAÇÃO FINAL**

> ⚠️ **Pré-requisitos obrigatórios:**
> 1. PRs 1-6 estáveis em prod por **mínimo 7 dias** sem incidentes
> 2. `attachments:legacy-drop-preview` retorna `missing=0` em **TODOS** os módulos
> 3. `attachments:integrity-check --all` retorna 0 falhas
> 4. Snapshot do DB tirado (pg_dump completo) — guardar 30 dias
> 5. Smoke test: download de 10 anexos aleatórios via `/api/v1/attachments/{id}/download` — todos abrem
> 6. **Aprovação explícita do user**

**Conteúdo:**
- Migrations dropando colunas: `users.profile_photo`, `expenses.receipt_path`, `expenses.receipt_original_name`, `timesheets.attachment_path`, `timesheets.attachment_original_name`, `hour_contributions.proposta_path`, `hour_contributions.proposta_original_name`, `stage_activity_events.attachment_*` (4 cols), `fechamento_notas.{nfse,nota_debito}_path`, `fechamento_notas.{nfse,nota_debito}_original_name`
- Migrations dropando tabelas dedicadas: `project_attachments`, `contract_attachments`, `project_message_attachments`, `contract_message_attachments`, `contract_request_message_attachments`
- Limpar reader-shim accessors (mantém só `attachmentUrl(category)`)
- Remover dual-write try/catches (controllers chamam só o service via `EntityAttachmentsPanel` / accessor)
- Remover models legados `*Attachment` (e relations correspondentes nos models pai)

**Rollback:** **NÃO TRIVIAL.** Restaurar do snapshot do DB. Por isso só rodar após semanas.

---

## Comandos úteis em prod (qualquer hora)

```bash
# Stats rápido
ssh minutor-prod 'docker compose exec backend php artisan attachments:stats'

# Stats em JSON pra alerta (cron):
ssh minutor-prod 'docker compose exec backend php artisan attachments:stats --json' | jq '.health.healthy'

# Health (foco em integridade)
ssh minutor-prod 'docker compose exec backend php artisan attachments:stats --health'

# Re-rodar backfill (idempotente, seguro)
ssh minutor-prod 'docker compose exec backend php artisan attachments:backfill --all'

# Verificar prontidão pra 11.4
ssh minutor-prod 'docker compose exec backend php artisan attachments:legacy-drop-preview'

# Forçar integrity check completo (não só últimos 7d)
ssh minutor-prod 'docker compose exec backend php artisan attachments:integrity-check --all'
```

---

## Comunicação ao time

Quando PR 4 for mergeado e backfill rodado, comunicar:

> 🎉 A camada global de anexos do Minutor está ATIVA em produção. Tudo continua funcionando como antes (legado intacto), mas todo upload novo já vai pra duas tabelas:
> - Coluna legada (como sempre)
> - Tabela `attachments` (nova, polimórfica)
>
> A migração FE virá em ondas. Painel admin de observabilidade em `/admin/attachments`.
>
> A deprecate da coluna legada virá **só após meses** observando estabilidade.

---

## Checklist de aprovação (você revisa antes de dar OK)

- [ ] PR 1 (Fundação) — `git log prod/main --since="48h"` está limpo perto do horário planejado?
- [ ] PR 2-4 (Dual-write) — backfill rodou OK em homolog antes de cada PR pra prod?
- [ ] PR 5 (FE) — TSC clean, e o Vercel preview funcionou no homolog?
- [ ] PR 6 (Observabilidade) — `/admin/attachments` carrega painel completo no homolog?
- [ ] PR 7 (Deprecate) — `attachments:legacy-drop-preview` com 0 missing, dump do DB tirado, ≥7 dias sem incidentes?

Quando estiver confortável, me chame que disparo o primeiro PR.
