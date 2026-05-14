# ADR 0006 — Risco operacional derivado, sem persistência

**Status:** Aceito · **Data:** 2026-05-14

## Contexto

Briefing das 12 melhorias §3 pede "risco operacional" por etapa (baixo/médio/alto) baseado em:
- consumo acelerado de horas
- etapa bloqueada
- atraso de prazo
- equipe estourada
- entregas atrasadas

A regra precisa ser:
- **objetiva** (sem IA, sem modelo treinado, sem heurística complexa)
- **derivada** de dados que já existem
- **simples de explicar** ao coordenador (tooltip com motivo)

## Decisão

**Não persistir** `risk_level`. Calcular em request, sempre fresco.

Mesmo padrão de ADR 0003 (`stage_allocations.actual_hours`): risco é função pura dos inputs e não há benefício em armazenar (sem histórico de risco; sem agregação cross-projeto que dependa de cache). Persistir gera drift inevitável quando inputs mudam.

### Helper

`App\Services\ProjectStageRiskService::compute($input): ['level' => ..., 'reasons' => [...]]`

Função pura. Inputs vêm do payload da etapa que já é calculado em `ProjectStageController::index`:
- `derived_status` (Fase 6)
- `expected_end_date`
- `days_since_activity` (Bloco A)
- `planned_hours`
- `actual_hours` (novo agregado — `computeConsumedHoursByStage`, único acréscimo)
- `team_overrun_count` (Fase 6)

### Regras (precedência alto > médio > baixo)

```
🔴 ALTO
  - derived_status = 'bloqueada' E days_since_activity > 7
  - expected_end_date < hoje E status != concluida
  - actual_hours / planned_hours > 1.10
  - team_overrun_count > 0

🟡 MÉDIO
  - prazo <= 7 dias e ainda não concluída
  - 0.85 < actual/planned <= 1.10
  - days_since_activity > 5 e não concluída
    (e não já caiu em "bloqueada >7d", que é override alto)

🟢 BAIXO — nenhuma das condições
```

Retorna **lista de razões** que dispararam (`risk_reasons: string[]`) pra tooltip mostrar motivo. Coordenador vê "Prazo vencido há 3d · Consumo 115% do planejado" em vez de só um dot vermelho.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| Coluna `risk_level` em `project_stages` | Drift. Recalcular é grátis em request normal. |
| Cache de risk_level | Inputs mudam a cada delivery move / aporte. Cache vira fonte de bug. |
| IA / heurística adaptativa | Não consegue explicar motivo simples; black box quebra confiança do coord. |
| Risco a nível de projeto (rollup) | Fora de escopo agora. Quando precisar: max(risk_level das etapas) — derivado, sem persistir. |

## Consequências

- **Boa:** mesma regra vale em todos os clients (front, BI, API externa). Helper é fonte única.
- **Boa:** ajustar threshold = mudar 1 número no `ProjectStageRiskService`. Sem migration.
- **Boa:** tooltip explica risco (não é só cor) — confiança maior do que dot opaco.
- **Operacional:** quando feedback do uso pedir nova regra (ex: "risco médio se ≥3 entregas em backlog"), adicionar no service e cobrir com teste unitário.

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Persiste `risk_level` em qualquer tabela
2. Cache que sobrevive entre requests sem invalidação explícita do payload
3. Coloca regras de risco fora do `ProjectStageRiskService` (espalhar = perder fonte única)
4. Adiciona modelo/ML pro cálculo
