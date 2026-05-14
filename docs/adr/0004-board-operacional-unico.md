# ADR 0004 — Board operacional único + capacidade por projeto

**Status:** Aceito · **Data:** 2026-05-14

## Contexto

Hoje existem 3 lugares onde o projeto operacional aparece visualmente:
1. `/contratos/pipeline` — kanban executivo (fluxo do contrato)
2. `/projetos/[id]/etapas` — grid de etapas do projeto
3. `/projetos/[id]/etapas/[stageId]` — kanban operacional **separado** por etapa

A separação entre #2 e #3 cria carga cognitiva alta, duplica info de header, e empurra o coordenador a abrir/fechar telas pra montar uma visão completa do projeto.

Além disso, em paralelo, há um **modelo dual** no produto: **sustentação** (alocação direta no projeto, sem etapas — funciona hoje) vs **projeto operacional** (etapas + entregas + alocações por etapa). A regra atual de diferenciação está em CLAUDE.md mas não em código.

## Decisão

### 1. Modelo dual explicitado em código

`Project::isOperational()` é a única função que define se um projeto é operacional ou sustentação. Regra (espelho do CLAUDE.md):

```php
$name = strtolower($this->serviceType?->name ?? '');
return !str_contains($name, 'sustenta')
    && !str_contains($name, 'cloud')
    && !str_contains($name, 'bizify');
```

Toda tela/endpoint que tem comportamento dual passa por esse helper. **Não duplicar a regra**.

### 2. Board operacional único

Projetos operacionais têm **uma única tela**: `/projetos/[id]` aba **Etapas**. Cada etapa renderiza inline:
- Header (nome, status macro, % consumo, barra, consultores alocados, 4 health dots)
- Kanban de entregas (5 colunas, DnD)

**Sem rota separada** `/projetos/[id]/etapas/[stageId]`. Será removida na Fase 1.

### 3. Capacidade master do projeto

`Project.sold_hours` é o teto de horas alocáveis em etapas.

**Invariante:**
```
SUM(stages.hours_planned) ≤ project.sold_hours
```

Violado em qualquer endpoint que ajusta `stages.hours_planned` (POST/PATCH stage, aporte futuro) → **422 com mensagem** `"Sem saldo disponível. Verifique com o coordenador."`

### 4. Capacidade da etapa

`ProjectStage.hours_planned` é o teto de alocação de consultores na etapa.

**Invariante:**
```
SUM(stage_allocations.planned_hours) ≤ stage.hours_planned
```

Violado em POST/PATCH allocation → mesma mensagem 422.

### 5. Apontamento bloqueado por saldo

Em projetos operacionais, quando o timesheet tem `stage_id`:
```
SUM(timesheet.effort_minutes_aprovados_pendentes_liberados) + novo
≤ stage_allocations.planned_hours × 60
```

Violado → 422 com mesma mensagem. Pendente conta porque vira aprovado quase sempre — bloquear só após aprovação seria tarde demais pro coord.

### 6. Sustentação intocada

Sustentação **não tem nenhuma dessas validações**. Fluxo atual de alocação direta no projeto, dashboards, apontamentos — tudo intocado. Etapas/entregas/allocations ficam invisíveis pra projetos de sustentação.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| Rota dedicada por etapa | Telas duplicadas, header repetido, DnD em contexto fragmentado |
| Misturar sustentação e operacional no mesmo projeto | Modelos têm regras de capacidade incompatíveis — confirmado com o usuário que não acontece |
| Permitir SUM > capacidade com warning | Briefing exige bloqueio. Mensagem padrão pede pra "verificar com o coordenador" — força conversa, não decisão silenciosa |
| Duplicar a regra `isOperational` em múltiplos lugares | Toda checagem passa pelo helper. Se a regra de "o que é sustentação" mudar, muda em 1 lugar |
| Validar saldo só com timesheets aprovados | Pendentes contam — bloquear no ponto de entrada evita aprovar e descobrir buraco depois |

## Mensagem padrão de violação de saldo

```
"Sem saldo disponível. Verifique com o coordenador."
```

Acompanhada de `detail` técnico (campo separado no JSON) com números pra debug.

## Cleanup

Implementações anteriores que conflitam serão removidas/refatoradas:
- `/projetos/[id]/etapas/[stageId]` — DELETE
- Side panel de etapas no pipeline — simplificar (vira só atalho)
- `ProjectStagesSidePanel.tsx` — mantém só botão "Abrir Workspace", remove lista detalhada

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Adiciona tela separada por etapa
2. Replica `isOperational` em hardcode
3. Permite alocação/apontamento que estoure capacidade sem bloquear
4. Aplica regra de etapas/entregas em projeto de sustentação
