# ADR 0009 — Cronograma e Board são duas views da mesma entidade

**Status:** Aceito · **Data:** 2026-05-16

## Contexto

O Minutor passou a precisar de planejamento operacional: uma tela onde o coordenador desenha o cronograma do projeto (etapas + atividades + datas + responsáveis) **antes** da execução. A tentação natural seria criar tabelas paralelas `stage_plans` / `activity_plans` que rascunham o cronograma e "publicam" gerando `project_stages` / `stage_deliveries` reais.

Avaliamos 3 opções:
1. **Reuso**: cronograma e board operam sobre as mesmas tabelas.
2. **Tabelas paralelas + publish engine**: plano vive em `stage_plans` / `activity_plans` e é "publicado" copiando pra estrutura operacional.
3. **Reuso + flag `is_draft`**: mesmas tabelas, mas com booleano que indica se a linha já está "publicada" ou ainda em rascunho.

## Decisão

**Reuso. Cronograma e Board são duas views da mesma entidade.**

Não existe "publicar cronograma". Não existem tabelas `stage_plans` / `activity_plans` paralelas. Não existe flag `is_draft`. Edição num lugar reflete imediatamente no outro porque é a mesma fonte de verdade.

### Modelo

```
project_stages — "etapa do cronograma" / "etapa do board" / "etapa da timeline"
stage_deliveries — "atividade do cronograma" / "atividade do board" / "atividade da timeline"
```

Adicionamos apenas o que falta:
- `stage_deliveries.planned_start_at` (date) — planejado início
- `stage_deliveries.actual_start_at` (datetime) — auto-set no primeiro move out of backlog
- `stage_deliveries.depends_on_delivery_id` (FK self nullable) — dependência leve (1 atividade depende de no máximo 1 outra)

`due_date` (= planejado fim) e `completed_at` (= real fim) já existem. `project_stages.stage_start_at` + `expected_end_date` (planejado início + fim da etapa) também já existem.

### Views

| View | Surface | Source |
|---|---|---|
| **Cronograma** | `/projetos/[id]/planejamento` | mesmas tabelas |
| **Board operacional** | `/projetos/[id]/etapas` | mesmas tabelas |
| **Timeline histórica** | `stage_activity_events` | derivado |

Arrastar uma atividade no cronograma (drag temporal) → PATCH `/deliveries/{id}` com `planned_start_at` + `due_date` atualizados. **O mesmo endpoint** que o kanban operacional usa pra alterar horas/responsável. Sem listeners de sync, sem publish jobs, sem dual write.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| Tabelas `stage_plans` / `activity_plans` paralelas | Cria drift, exige sync engine, dobra UI, lentidão operacional. ERP real não funciona com plano congelado — coord ajusta em tempo real. |
| Flag `is_draft` em stages/deliveries | Dualidade operacional: atividades reais vs draft. Filtros, permissões, drafts esquecidas, publicação parcial — toda a complexidade que estávamos evitando. |
| Botão "Publicar cronograma" | Não há momento atômico de publicação. Plano vive evoluindo junto com a execução. |
| Engine de cálculo de critical path / slack | Fora de escopo. Dependência é apenas indicador visual leve. |
| DAG complexa (múltiplos predecessors) | `depends_on_delivery_id` é FK única. 1 atividade depende de no máximo 1 outra. Resolve 90% dos casos ERP sem virar PMO. |
| Cálculo automático de data baseado em dependência | Coord move atividade conforme contexto humano (cliente atrasou, GMUD adiada, etc). Engine que recalcula auto vira inimigo. |

## Consequências

- **Boa:** uma única verdade operacional. Edição no Gantt → aparece no kanban instantaneamente. Sem dual store, sem drift.
- **Boa:** menos backend (sem sync jobs, sem listeners, sem publish engine, sem duplicação de validação).
- **Boa:** menos bugs operacionais — não há "mudou no board mas não no plano".
- **Boa:** preparação pra métricas reais (planejado × real) via `planned_start_at` vs `actual_start_at` e `due_date` vs `completed_at`. Análise de previsibilidade fica trivial.
- **Operacional:** cronograma é entry-point primário sugerido, mas os botões "Nova etapa" / "Nova atividade" do board atual continuam funcionais — não destrutivo.

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Cria tabela paralela `stage_plans` / `activity_plans` / qualquer outra que duplica `project_stages` / `stage_deliveries`.
2. Adiciona flag `is_draft` / `is_published` / equivalente.
3. Cria endpoint "publicar cronograma" ou sync job entre plano e operação.
4. Adiciona `depends_on` array (DAG) — manter FK única leve.
5. Adiciona cálculo automático de datas baseado em dependência — UX deve ser manual.

## Relacionados

- Estende ADR 0004 (board operacional único): agora a tela `/planejamento` é uma view adicional sobre as mesmas entidades.
- Estende ADR 0007 (atividade unidade de execução): cronograma confirma que a atividade carrega responsável, horas, prazo, dependência.

---

## Appendix — 2026-05-15

Após o cronograma rodar em DEV1 com coords ativos, três lacunas concretas apareceram. O appendix consolida o que mudou sem reabrir as decisões principais.

### Adições de schema
- `stage_deliveries.dependency_type` varchar(8) default `'FS'` — coluna existe para evolução futura (SS/FF/SF) sem nova migration. **Whitelist atual = `['FS']`**: backend rejeita outros valores com 422.
- `project_stages.actual_start_at` / `actual_end_at` (dateTime nullable) — rollup das datas reais das atividades, mantido pelo `StageDeliveryObserver`. `actual_end_at` só preenche quando todas as atividades da etapa estão `done`.

### Calendário útil canônico
- Novo `App\Services\BusinessCalendarService`. Único caminho para aritmética de datas planejadas. Consome `holidays` (tabela já existente) e considera sábados/domingos.
- `HourBankService::calculateWorkingDays` continua existindo, **restrito a contagem mensal de banco de horas**. Não usar para somar dias úteis em prazos de cronograma — usar `BusinessCalendarService`.

### Cálculo automático com confirmação
A regra de revisão #5 original ("UX deve ser manual") permanece para datas de _operação_, mas é refinada para _planejamento_:

- O sistema **sugere** `due_date` quando o coord preenche `planned_start_at + hours_planned` deixando o fim vazio (`addBusinessHours(start, hours, 8)`). A sugestão é aplicada na criação se o usuário não a sobrescrever.
- Após editar `planned_start_at` / `due_date` / `hours_planned` de uma atividade com dependentes diretos, o frontend abre **modal de confirmação** `Recalcular dependentes? [Manter] [Recalcular cascata]`. Default = **Manter**. Cascade só roda via apply explícito.
- Predecessor `FS` com `status != 'done'` **bloqueia operacionalmente** o move `backlog → *` da dependente (HTTP 422 no backend, toast no frontend). **Planejamento (edit de datas/horas) nunca é bloqueado**.

### Regra de revisão atualizada

Substitui a regra #5 anterior:

> 5. **Recalcular cascata sem confirmação do coord.** Engine de cascade existe (`POST /deliveries/{id}/recalc-dependents`), mas só roda com `apply: true` vindo de ação humana explícita.

Mantém #4 (sem múltiplos predecessors); estende implicitamente: o `dependency_type` aceita apenas `FS` até que um novo ADR justifique adicionar SS/FF/SF.
