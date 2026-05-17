# ADR 0008 — `stage_activity_events.delivery_id` opcional (estende ADR 0005)

**Status:** Aceito · **Data:** 2026-05-15

## Contexto

ADR 0005 trava "timeline operacional consolidada em `stage_activity_events`" — tabela única append-only. Originalmente todos os eventos eram stage-level (`stage_id` + tipo + payload).

Com o refactor da ADR 0007 (atividade como unidade de execução), comentários e anexos passam a viver na atividade. Eventos automáticos cross-feature (delivery_moved, aporte_created, etc) também ganham contexto de atividade.

Pergunta: criar tabela paralela `activity_messages` / `activity_attachments`? Estender `stage_activity_events`?

## Decisão

**Estender `stage_activity_events` com `delivery_id` FK nullable.** Sem tabela paralela.

```sql
ALTER TABLE stage_activity_events
  ADD COLUMN delivery_id BIGINT NULL
    REFERENCES stage_deliveries(id) ON DELETE SET NULL;
CREATE INDEX ON stage_activity_events (delivery_id, created_at);
```

### Semântica do delivery_id

- `delivery_id IS NOT NULL` → evento tem escopo de atividade. Aparece na timeline da atividade (`GET /activities/{id}/activity`) e também na agregada da etapa.
- `delivery_id IS NULL` → evento é puramente stage-level (block_set/cleared, eventos antigos do legacy stage-level chat/aporte). Aparece só na timeline agregada da etapa.

### Filtros

- `GET /stages/{id}/activity` — todos eventos com `stage_id=X` (inclui os com `delivery_id` setado). Timeline agregada da etapa.
- `GET /activities/{id}/activity` — só eventos com `delivery_id=X`. Timeline da atividade.

### Anexos (Pilar 3 prévio)

`stage_activity_events.attachment_path/original_name/mime/size` (colunas adicionadas no PR #48 — Pilar 3 anterior) viajam com o evento. Se evento tem `delivery_id=X` e attachment, o anexo é "anexo da atividade X". Não há tabela `activity_attachments` paralela.

### Comentários

`POST /activities/{id}/comments` cria evento `type=comment` com `delivery_id` setado + payload `{text, mentioned_user_ids[]}` + colunas attachment opcionais.

## Consequências

- **Boa:** uma tabela, uma fonte de verdade. Mesma ferramentaria (events, audit, IA) cobre tudo.
- **Boa:** zero migração destrutiva. Linhas antigas (`delivery_id=null`) ficam intactas, visíveis na timeline da etapa.
- **Boa:** índice composto `(delivery_id, created_at)` permite query rápida de timeline da atividade.
- **Operacional:** quem mostra "todos os comentários do projeto" pode juntar via JOIN `project_stages → stage_activity_events` filtrando type=comment. Sem fan-out de tabelas.

## Não fazemos

| Prática | Por quê |
| --- | --- |
| Tabela `activity_messages` paralela | Quebra ADR 0005. Dobra schema sem ganho — coluna `delivery_id` resolve. |
| Tabela `activity_attachments` separada | Idem. Anexo viaja com o evento. |
| Migrar eventos legacy stage-level pra atividade automaticamente | Não há critério bom pra mapear evento da etapa pra uma atividade específica. Eventos antigos ficam como agregado da etapa (read-only). |
| `delivery_id NOT NULL` na coluna | Bloquearia eventos stage-only (block_set, futuras métricas agregadas). Manter nullable. |

## Regra de revisão

PR é rejeitada (sem novo ADR) se:
1. Cria tabela paralela pra mensagem/anexo da atividade
2. Move colunas `attachment_*` pra outra tabela
3. Torna `delivery_id` NOT NULL (perderia eventos stage-only legítimos)
4. Cria endpoint que insere em `stage_activity_events` sem setar `delivery_id` quando o contexto é atividade

## Relacionados

- Estende ADR 0005 (timeline consolidada)
- Implementa ADR 0007 (atividade como unidade)
