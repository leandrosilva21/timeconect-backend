# ADR 0001 — Minutor não é Jira

**Status:** Aceito · **Data:** 2026-05-13

## Contexto

Estamos introduzindo gestão operacional de projetos (etapas + entregas + kanban). É o tipo de feature que historicamente puxa o produto para o lado de "ferramenta de gestão ágil genérica" (Jira/ClickUp/Asana). O diferencial do Minutor não é gestão ágil — é **execução operacional integrada a horas, contratos e financeiro**.

## Decisão

O Minutor adota gestão operacional **mínima**, ancorada em três níveis: projeto · etapa · entrega. Tudo o que estiver fora dessa hierarquia, ou que adicione complexidade típica de ferramenta ágil, fica explicitamente **fora de escopo**.

### Não fazemos (e não vamos fazer sem novo ADR)

| Conceito | Por quê não |
| --- | --- |
| Épicos / múltiplos níveis de agrupamento | Hierarquia fixa 3 níveis. Mais que isso vira árvore que ninguém mantém. |
| Subtarefas (entregas filhas) | Quebra o earned value por hora. Se quebrar, vira nova etapa. |
| Dependências entre cards (blocked-by, blocks) | Em consultoria ERP as dependências são gerenciadas no plano executivo do coordenador, não no card. |
| Workflows customizáveis por cliente | Os 5 status de entrega cobrem 100% dos casos. Customização gera fragmentação e suporte caro. |
| Sprints / planning poker / story points | Não trabalhamos com sprints. Estimativa é em horas, não em pontos. |
| Automações tipo Jira (regras condicionais por usuário) | Postergado indefinidamente. Risco de virar plataforma de configuração em vez de plataforma operacional. |
| Comentários em entregas (V1) | Postergado para V2. Hoje conversa acontece em ferramentas externas; comentário interno duplica canal. |
| Time tracking em entrega (timer rodando) | Apontamento já existe e é o caminho oficial. Timer no card é UX de produto diferente. |

### Fazemos

- 3 níveis fixos: projeto · etapa · entrega
- Apontamento direto na entrega (`stage_delivery_id` nullable em `timesheets`)
- Progresso automático ponderado por horas planejadas
- Health multi-dimensional: prazo · horas · entrega
- Timeline automática de eventos da entrega (sem comentários humanos em V1)

## Regra de revisão

Toda PR que adicione qualquer item da lista de "não fazemos" precisa:

1. Um novo ADR substituindo este;
2. Aprovação explícita de quem mantém o produto.

Sem ADR novo, a PR é fechada.

## Consequências

- **Boa:** o produto continua reconhecível como "plataforma operacional de consultoria", não como "Jira-light".
- **Ruim:** vamos ouvir pedidos de features típicas de Jira ao longo do tempo. Cada pedido é uma oportunidade de re-explicar o posicionamento; a maioria deve ser recusada.
- **Operacional:** ao revisar PRs com escopo grande na área de projetos, este ADR é o primeiro check.
