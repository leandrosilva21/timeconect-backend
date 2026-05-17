# ADR 0007 — Atividade é a unidade de execução; etapa é agrupador macro

**Status:** Aceito · **Data:** 2026-05-15

## Contexto

Na primeira fase do board operacional (ADR 0003/0004), `stage_allocations`, `stage_hour_aportes`, comentários e anexos foram colocados no nível da **etapa**. Conceitualmente errado:

- Uma etapa agrega múltiplas frentes de trabalho (ex: "Fiscal" tem SPED Fiscal, ICMS, EFD-Reinf, etc.)
- Comunicação operacional é **contextual**: time discute SPED Fiscal, não "Fiscal em geral"
- Alocação de horas precisa amarrar consultor a uma frente específica (Maria 20h em SPED Fiscal), não à etapa toda
- Aporte (+5h) faz sentido na frente que precisou, não diluído na etapa
- Anexos (GMUD, XML, evidência) pertencem à atividade que os gerou

A unidade real de execução já existia: `stage_deliveries` (que no DB ficou com esse nome legado). A partir desta fase, ela passa a ser tratada como **atividade** no surface, e ganha ownership de alocação/chat/aporte/anexos.

## Decisão

**A atividade (`stage_delivery`) é a unidade de execução.** A etapa é apenas agrupador macro: consolida atividades, mostra status macro derivado (planejamento/execução/homologação/bloqueada/concluída) e risco — mas não tem mais alocação direta, chat ou aporte próprios.

### Hierarquia

```
PROJETO   — prazo macro, saldo total, equipe consolidada
  └─ ETAPA   — agrupador read-only (status derivado, risco, timeline agregada)
      └─ ATIVIDADE   — execução: responsável, prazo, horas, chat, anexos, aportes
          └─ CONSULTOR   — alocações com horas planejadas (stage_allocations.delivery_id)
```

### Migração não destrutiva

- Schema do DB **mantém nomes legados**: `stage_deliveries`, `stage_delivery_id`, `stage_allocations`, `stage_hour_aportes`, `stage_activity_events`. Surface PHP/TS/UI fala "activity"/"atividade".
- Tabelas ganham `delivery_id` FK nullable (`stage_allocations`, `stage_hour_aportes`, `stage_activity_events`). Linhas antigas com `delivery_id=null` são preservadas como stage-level (compat com histórico).
- Endpoints `/stages/{id}/comments`, `/stages/{id}/allocations`, `/stages/{id}/aportes` ficam funcionais (deprecated soft). Novos endpoints `/activities/{id}/...` espelham e setam `delivery_id`.

### Saldo do projeto

Antes: `project.sold_hours − SUM(project_stages.hours_planned)`.
Agora: `project.sold_hours − SUM(stage_deliveries.hours_planned)` em todas as etapas. `stage.hours_planned` vira cap manual opcional / informacional.

Aporte na atividade incrementa `stage_deliveries.hours_planned` (não `project_stages.hours_planned`). Stage-level aporte legacy continua incrementando o antigo — coexistência prevista.

## Consequências

- **Boa:** comunicação contextual real. Coord abre SPED Fiscal e vê só conversa dela.
- **Boa:** alocação fica granular. Permite "Maria 20h em SPED, Ricardo 10h em ICMS" no mesmo stage Fiscal.
- **Boa:** aporte registra a frente exata que precisou. Auditoria fica mais clara.
- **Boa:** etapa fica leve: card central só lê (status, progresso, risco). DnD continua só no kanban operacional.
- **Operacional:** linhas legacy `stage_*` com `delivery_id=null` aparecem na timeline agregada da etapa (read-only). Coord pode migrar manualmente via UI se quiser; sem automação destrutiva.
- **Operacional:** sustentação intocada — `Project::isOperational()` continua gateando UI.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| Renomear tabelas DB (`stage_deliveries` → `activities`) | Quebra timesheets, hooks, observers, queries diretas. Compat retroativa pediu manter nome. |
| Migração automática stage→activity das linhas legacy | Associação correta depende de contexto humano (qual atividade da etapa? a primeira? a com mais horas? nenhuma?). Risco de associação errada é maior que o ganho. |
| Manter UI stage-level coexistindo (composer/aporte/allocation no drilldown da etapa) | Confunde. Decisão: hide UI, mantém endpoints só pra rollback rápido. |
| Criar tabela paralela `activity_allocations`/`activity_messages` | Conflita com ADR 0005 (timeline única) e dobra schema. Coluna `delivery_id` resolve sem duplicar. |

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Cria nova tabela `activity_*` paralela quando uma coluna `delivery_id` resolveria
2. Adiciona alocação/chat/aporte/anexo na etapa (em vez da atividade)
3. Renomeia colunas DB existentes (`stage_delivery_id` → `activity_id`) — quebra compat
4. Migra automaticamente linhas legacy stage-level pra activity-level sem confirmação humana

## Relacionados

- Estende e parcialmente revoga ADR 0003 (`stage_allocations` deriva via subquery — agora também pode ter `delivery_id`)
- Estende ADR 0004 (board operacional único)
- Ver ADR 0008 sobre como `stage_activity_events` ganha `delivery_id` opcional
