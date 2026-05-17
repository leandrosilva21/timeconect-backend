# ADR 0003 — `stage_allocations`: capacidade na etapa, consumo derivado

**Status:** Aceito · **Data:** 2026-05-14

## Contexto

Adicionamos alocação operacional de consultoria por etapa. Existem 3 grandezas naturais:
- `planned_hours` — quanto a etapa reservou pro consultor
- `actual_hours` — quanto o consultor já apontou
- `remaining_hours` — diferença

A tentação é persistir as 3. A consequência seria: 1 caneta escreve (frontend setando planned), outra caneta escreve (Observer no Timesheet recalculando actual), e a chance de divergência é alta — race conditions em inserção/edição/soft-delete, lag do Observer, status do timesheet mudando depois (approve/reject), filtros que mudam o que conta como consumo.

## Decisão

**Persiste apenas `planned_hours` em `stage_allocations`.** As outras 2 são **sempre derivadas em query**:

```sql
actual_hours    = Σ effort_minutes/60 de timesheets onde stage_id e user_id batem
                  E status IN ('approved','released') E não soft-deletado
remaining_hours = planned_hours − actual_hours      (PHP/JS)
```

Toda métrica derivada vive no `StageAllocationController` ou em um service público — nunca em coluna.

### Não fazemos

| Prática | Por quê |
| --- | --- |
| Coluna `actual_hours` em `stage_allocations` | Drift garantido. Quem segura status approved↔released↔rejected? E soft-delete? E edição manual de horas pelo coord? |
| Observer no Timesheet recalculando | Race condition em transações simultâneas. E observer falha silenciosamente quando a transação dá rollback. |
| Cache antecipado | YAGNI. Otimiza depois de medir. |
| Materialized view automática | Mesmo problema do observer + complexidade adicional. |

### Fazemos

- Query agregada com LEFT JOIN no controller. Índice composto `(stage_id, user_id, status)` em `timesheets` (`WHERE stage_id IS NOT NULL`) garante velocidade.
- `health` por alocação calculado em PHP — função pura, sem persistência.
- `health` por etapa = rollup das alocações + dimensões existentes (prazo, horas, entregas, equipe).

## Regra do contador

Status que **consomem** capacidade: `approved`, `released`. Pendentes (`pending`, `conflicted`, `adjustment_requested`) **não** consomem — apenas após aprovação. Isso evita "voltar atrás" no health quando uma hora é rejeitada.

Soft-delete (`deleted_at`) sempre exclui da soma.

## Consequências

- **Boa:** uma fonte de verdade pra cada métrica. Frontend sempre vê dado fresco.
- **Boa:** elimina classe inteira de bugs (drift, race, soft-delete).
- **Boa:** cliente da API não precisa "rodar refresh" antes de ver consumo atualizado.
- **Operacional:** se ficar lento, otimização tem caminho claro (cache curto, view materializada com refresh por demanda) — mas só implementa após medir.

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Adiciona coluna persistida pra `actual_hours` / `remaining_hours` / `health`.
2. Adiciona Observer no Timesheet escrevendo em `stage_allocations`.
3. Cria cache que sobrevive entre requests sem invalidação explícita.

## Permissão de remoção

Remover alocação **não bloqueia mesmo se houver consumo**. Frontend deve mostrar confirm dialog avisando:
- Timesheets permanecem com `stage_id` preservado (histórico intocado).
- Apenas o planejamento operacional é removido.

Isso evita fricção operacional (coord preso) sem perder rastreabilidade.
