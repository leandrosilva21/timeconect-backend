# ADR 0002 — Projeto: lifecycle único, projeções múltiplas

**Status:** Aceito · **Data:** 2026-05-13

## Contexto

O Minutor tem hoje 3 kanbans que olham para projetos/contratos:

1. **Kanban de Contratos** (`/contratos/kanban`) — source of truth do fluxo comercial. DnD entre colunas de coordenador, regras de sustentação, estados terminais; sincroniza com várias rotinas. Custou caro estabilizar.
2. **Kanban de Projetos Operacional** (`/projetos/kanban`) — visão operacional/executiva dos projetos (Backlog → Execução → Homologação → Encerrado/Pausado/Cancelado).
3. **Kanban de Etapa** (`/projetos/[id]/etapas/[stageId]`) — fluxo das entregas dentro de uma etapa. Tabela separada (`stage_deliveries.status`), sem conflito com este ADR.

Na Fase 4 nascemos com o erro de criar uma coluna nova **`projects.kanban_stage`** persistida, paralela ao `projects.status`. Isso significaria duas máquinas de estados, dois pontos de gravação, dois pontos de sincronização. Tinha tudo pra divergir.

## Decisão

**`projects.status` é a única máquina de estados do projeto.** Toda visão visual (kanban operacional, kanban global executivo, badges, filtros, cliente) **deriva** de `status` — nada persiste "qual coluna eu estou".

### Lifecycle real (único)

| Valor | Significado operacional |
| --- | --- |
| `awaiting_start` | Projeto criado, ainda sem coordenador alocado |
| `backlog` | **Coordenador alocado**, mas operação real ainda não começou |
| `started` | Em execução real (alguém apontou hora ou coord moveu manualmente) |
| `liberado_para_testes` | Em homologação |
| `finished` | Encerrado |
| `paused` | Pausado |
| `cancelled` | Cancelado |

### Quem escreve `status`

| Origem | Transição |
| --- | --- |
| Kanban de Contratos → arrastar pra coordenador | cria projeto em `backlog` |
| Kanban Operacional `/projetos/kanban` → DnD entre colunas | escreve o `status` correspondente à coluna destino |
| Apontamento de hora (TimesheetController) | auto-transição: `awaiting_start` ou `backlog` → `started` |
| Tela de edição manual de projeto | edição direta com permissão `projects.update` |

### Quem deriva a coluna

`App\Services\ProjectWorkflowService` é o único lugar onde existe o mapping status ↔ coluna visual. Constants:

- `PIPELINE` — projetos vivos no pipeline (`awaiting_start`, `backlog`, `started`, `liberado_para_testes`). Use em listagens, dropdowns, visões de coordenação, kanbans operacionais.
- `IN_EXECUTION` — projetos em execução real (`started`, `liberado_para_testes`). Use em SLA, produtividade, consumo, margem.
- `TERMINAL` — terminais (`finished`, `paused`, `cancelled`).
- `OPERATIONAL_COLUMN` — mapping `status → coluna` no kanban operacional.

Helpers: `getOperationalColumn()`, `getStatusForOperationalColumn()`, `isPipeline()`, `isInExecution()`, `isTerminal()`.

### Scopes Eloquent

- `Project::active()` — exclui terminais (`paused`, `finished`, `cancelled`). Já inclui `backlog` por construção (excludente).
- `Project::open()` — exclui só `finished` e `cancelled`. Aceita lançamentos de hora/despesa.
- `Project::inExecution()` — **novo**. Filtra por `IN_EXECUTION`. Use em consumo operacional, SLA, produtividade.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| `projects.kanban_stage` (coluna persistida) | Cria máquina paralela. Deprecada e removida do `$fillable`. Coluna no DB fica zombie até cleanup futuro. |
| `if ($status === 'started')` espalhado pelo código | Cada repetição é uma chance de divergência. Sempre passe por `ProjectWorkflowService`. |
| Sincronizadores entre kanbans | Não precisam existir — todos olham para o mesmo `status`. |
| Visão de cliente com `status` técnico exposto | Cliente vê labels macro humanas, derivadas — nunca o valor cru. |

## Consequências

- **Boa:** uma única caneta escreve no estado. Backups, BI, análises e filtros sempre veem a mesma verdade.
- **Boa:** kanban de contratos não foi tocado — fluxo histórico preservado.
- **Operacional:** ao revisar PR que toque em `projects.status`, este ADR é o primeiro check. Qualquer PR que persista coluna visual = rejeitada com pedido de novo ADR.

## Regras de revisão

PR é rejeitada (sem ADR novo) se:
1. Adiciona coluna persistida que duplique a noção de "qual estado/coluna".
2. Adiciona caller de status em hard-code fora do `ProjectWorkflowService`.
3. Expõe `status` cru para perfil cliente.
4. Cria sincronização periódica/automática entre kanbans.

## Cleanup futuro

- `projects.kanban_stage` é zombie hoje (sem read/write). Em ~3 meses, se nada quebrar, dropar coluna em migration limpa.
- Backfill de projetos em `started` que deveriam estar em `backlog`: **não** vamos fazer. Eles continuam em `started` (a coluna "Em Execução" no kanban global vai incluí-los normalmente). Backfill seria invasivo e ambíguo.
