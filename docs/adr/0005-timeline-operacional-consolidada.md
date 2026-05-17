# ADR 0005 — Timeline operacional consolidada em `stage_activity_events`

**Status:** Aceito · **Data:** 2026-05-14

## Contexto

A gestão operacional ganha agora 12 melhorias de leitura — entre elas, "timeline operacional da etapa" (briefing 12-melhorias §5), "última movimentação" (§2), "alerta de etapa parada" (§6) e suporte derivado para "risco operacional" (§3, ver ADR 0006).

Hoje os eventos relevantes vivem em 3 lugares distintos:
- `delivery_events` — created/status_changed/reassigned/completed por entrega
- `timesheets` — apontamentos (com `stage_id`)
- `stage_hour_aportes` — aportes operacionais

Pra montar a timeline operacional, dois caminhos óbvios:

| Caminho | Custo | Risco |
| --- | --- | --- |
| Consolidar em query (`UNION ALL`) das 3 tabelas | barato (sem migration) | lento; lógica espalhada; cada nova fonte precisa entrar no UNION |
| Tabela append-only única `stage_activity_events` | 1 migration + observers | duplicação leve de eventos; sincronização |

## Decisão

**Tabela única `stage_activity_events`**, append-only. Toda atividade operacional relevante da etapa entra ali via Observer/serviço.

### Schema

```
id              bigserial
stage_id        FK project_stages (cascade)
actor_user_id   FK users (nullable, set null)
type            varchar(30)
payload         jsonb
created_at      timestamp default now()

INDEX (stage_id, created_at)
INDEX (type)
```

Sem `updated_at`. Sem soft-delete. Registros nunca mudam — se algum dado precisa de correção, criar novo evento (ex: `correction` no `type`).

### Tipos atuais

- `delivery_created`, `delivery_moved`, `delivery_completed` — espelho dos eventos de `delivery_events`
- `hours_logged` — quando timesheet é criado com `stage_id`
- `aporte_created` — quando `StageHourAporte` é criado
- `block_set`, `block_cleared` — bloqueio contextual (§1)
- `comment` — futuro (§5 menciona comentários humanos)

Nova fonte = novo tipo + observer/dispatch que escreve aqui. Não cria tabela paralela.

### Relação com `delivery_events`

`delivery_events` continua existindo. Resumo:
- `delivery_events` = histórico técnico **por entrega** (granular, focado no ciclo da entrega)
- `stage_activity_events` = visão operacional **por etapa** (agrega múltiplas fontes)

Quando um delivery move, ambos os logs registram. Duplicação aceita porque os consumidores são diferentes: timeline da entrega (drilldown card → side panel) vs timeline da etapa (kanban central + drilldown).

### Não fazemos

| Prática | Por quê |
| --- | --- |
| Substituir `delivery_events` | É histórico granular ainda útil; quebra consumidores existentes |
| Persistir agregados (`last_activity_at` em `project_stages`) | Drift de cache. Vem via `withMax(...)` no controller, sempre fresco |
| Permitir UPDATE/DELETE em registros | Quebra auditoria. Correção = novo registro |
| Backfill dos eventos antigos pra essa tabela | Não vale o esforço. Timeline começa a partir de agora |

### Quem escreve

| Origem | Tipo | Onde no código |
| --- | --- | --- |
| `StageDeliveryObserver` (já existe) | `delivery_created`, `delivery_moved`, `delivery_completed` | `app/Observers/StageDeliveryObserver.php` |
| `StageHourAporteObserver` (novo) | `aporte_created` | `app/Observers/StageHourAporteObserver.php` |
| `TimesheetObserver` ou store | `hours_logged` (quando stage_id setado) | Futuro |
| `ProjectStageController::update` | `block_set`, `block_cleared` | Bloco B do plano |
| Endpoint de comentário (futuro) | `comment` | — |

## Consequências

- **Boa:** 1 fonte de verdade pra "última movimentação" e "atividade da etapa"
- **Boa:** novo tipo é uma linha — sem schema migration
- **Boa:** payload `jsonb` permite contexto rico sem coluna nova
- **Ruim:** duplicação leve com `delivery_events` (mesmos eventos em 2 lugares). Trade-off aceito.

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Cria nova tabela paralela pra timeline operacional
2. Adiciona UPDATE/DELETE em `stage_activity_events`
3. Persiste agregados como `last_activity_at` em `project_stages`
4. Faz UNION runtime de tabelas pra simular timeline (perpetua o problema antigo)
