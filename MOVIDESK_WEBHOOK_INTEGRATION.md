# 🎫 Movidesk Webhook - Integração de Apontamento Automático de Horas

## 📋 Visão Geral

Este documento descreve a integração implementada entre o webhook do Movidesk e o sistema Time Conect para criar automaticamente apontamentos de horas (timesheets) a partir de tickets do Movidesk.

## 🔄 Fluxo de Processamento

Quando o webhook do Movidesk recebe uma notificação de ticket:

1. O sistema busca os detalhes completos do ticket via API do Movidesk
2. Processa a primeira ação (`actions[0]`) do ticket
3. Extrai o primeiro apontamento de tempo (`timeAppointments[0]`)
4. Cria automaticamente um timesheet no sistema Time Conect

## 📊 Mapeamento de Dados

### 1. **user_id** - Busca por Email
- **Origem**: `full_ticket_details.actions[0].createdBy.email`
- **Processo**: 
  - Busca usuário no sistema pelo email
  - Verifica se o usuário está ativo (`enabled = true`)
  - Se não encontrar, registra em log e **não cria** o apontamento

### 2. **customer_id** - Busca por Nome da Organização
- **Origem**: `full_ticket_details.clients[x].organization.businessName`
- **Processo**:
  - Se houver **múltiplos clientes** no array `clients`:
    - E um deles contém `@erpserv.com.br` no email
    - Usa o **outro cliente** (não ERPSERV)
  - Busca o cliente no sistema pelo `name` ou `company_name`
  - Se **não encontrar**:
    - Registra em log
    - Usa o cliente padrão do SystemSetting: `movidesk_default_customer_id`

### 3. **project_id** - Projeto Padrão
- **Origem**: SystemSetting `movidesk_default_project_id`
- **Validação**:
  - Verifica se o projeto existe
  - Verifica se está ativo (não cancelado ou finalizado)
  - Se inválido, registra em log e **não cria** o apontamento

### 4. **date** - Data do Serviço
- **Origem**: `full_ticket_details.actions[0].timeAppointments[0].date`
- **Formato Original**: `"2025-12-03T00:00:00"`
- **Formato Convertido**: `"2025-12-03"` (Y-m-d)

### 5. **start_time** - Horário de Início
- **Origem**: `full_ticket_details.actions[0].timeAppointments[0].periodStart`
- **Formato Original**: `"20:00:00"`
- **Formato Convertido**: `"20:00"` (H:i)

### 6. **end_time** - Horário de Término
- **Origem**: `full_ticket_details.actions[0].timeAppointments[0].periodEnd`
- **Formato Original**: `"22:00:00"`
- **Formato Convertido**: `"22:00"` (H:i)

### 7. **effort_hours** - Horas de Esforço
- **Origem**: `full_ticket_details.actions[0].timeAppointments[0].workTime`
- **Formato Original**: `"02:00:00"`
- **Formato Convertido**: `"2:00"` (H:MM)

### 8. **effort_minutes** - Minutos de Esforço
- **Cálculo**: Converte `effort_hours` para minutos
- **Exemplo**: `"2:00"` → `120 minutos`
- **Fórmula**: `(horas × 60) + minutos`

### 9. **observation** - Observação/Descrição
- **Composição**:
  ```html
  <h2>{full_ticket_details.subject}</h2><br/>{full_ticket_details.actions[0].htmlDescription}
  ```
- **Exemplo**:
  ```html
  <h2>Teste webhook</h2><br/><p>Teste de integração com API</p>
  ```

### 10. **ticket** - Número do Ticket
- **Origem**: `full_ticket_details.id`
- **Exemplo**: `"45127"`

## ⚙️ Configurações Necessárias

### SystemSettings (Banco de Dados)

Você precisa configurar estes dois campos na tabela `system_settings`:

```sql
-- Cliente padrão para quando não encontrar o cliente do ticket
INSERT INTO system_settings (key, value, type, `group`, description) 
VALUES ('movidesk_default_customer_id', '1', 'integer', 'movidesk', 'ID do cliente padrão para tickets do Movidesk');

-- Projeto padrão para todos os apontamentos do Movidesk
INSERT INTO system_settings (key, value, type, `group`, description) 
VALUES ('movidesk_default_project_id', '1', 'integer', 'movidesk', 'ID do projeto padrão para tickets do Movidesk');
```

### Variáveis de Ambiente (.env)

```env
# Token de API do Movidesk
MOVIDESK_TOKEN=seu_token_aqui
```

### Configuração no Movidesk

1. Acesse o Movidesk → Configurações → Webhooks
2. Crie um novo webhook
3. **URL**: `https://seu-dominio.com/api/v1/movidesk/webhook/ticket`
4. **Eventos**: Selecione os eventos que devem disparar o webhook (ex: "Ação adicionada")
5. **Método**: POST

## 📝 Logs e Monitoramento

Todos os passos do processamento são registrados em logs com emojis para facilitar a identificação:

### Tipos de Log

- ✅ **Sucesso**: Operação concluída com êxito
- ⚠️ **Aviso**: Dado não encontrado, usando fallback
- 🚨 **Erro**: Erro crítico, apontamento não criado
- ℹ️ **Info**: Informação de processamento
- ⏭️ **Skip**: Processamento pulado (sem ações ou timeAppointments)
- 🎫 **Webhook**: Log geral do webhook
- ⚙️ **Processamento**: Início do processamento de dados

### Exemplos de Log

```
✅ [MOVIDESK WEBHOOK] Usuário encontrado
   user_id: 5
   user_name: João Silva
   email: joao@empresa.com

⚠️ [MOVIDESK WEBHOOK] Cliente não encontrado no sistema
   organization_name: Cliente ABC
   
ℹ️ [MOVIDESK WEBHOOK] Usando cliente padrão
   default_customer_id: 1

✅ [MOVIDESK WEBHOOK] Apontamento criado com sucesso
   timesheet_id: 123
   user_id: 5
   ticket: 45127
   date: 2025-12-03
   effort_hours: 2:00
```

## 🔍 Validações Implementadas

### 1. Verificação de Duplicados
Antes de criar, verifica se já existe apontamento com:
- Mesmo `ticket`
- Mesmo `user_id`
- Mesma `date`
- Mesmo `start_time`

Se encontrar, **não cria** e registra em log.

### 2. Validação de Usuário
- Email deve existir no sistema
- Usuário deve estar ativo (`enabled = true`)

### 3. Validação de Cliente
- Cliente deve existir e estar ativo
- Se não encontrar, usa cliente padrão

### 4. Validação de Projeto
- Projeto deve existir
- Projeto deve estar ativo (não cancelado ou finalizado)

### 5. Status do Timesheet
- Todos os apontamentos são criados com status `pending`
- Precisam ser aprovados posteriormente

## 🛠️ Tratamento de Erros

### Erros que Impedem Criação

1. **Usuário não encontrado ou inativo**
   - Log: `⚠️ Usuário não encontrado ou inativo`
   - Ação: Não cria o apontamento

2. **Projeto não configurado ou inativo**
   - Log: `🚨 Projeto padrão não configurado em SystemSettings`
   - Ação: Não cria o apontamento

3. **Cliente padrão não configurado** (quando não encontra o cliente)
   - Log: `🚨 Cliente padrão não configurado em SystemSettings`
   - Ação: Não cria o apontamento

4. **Dados obrigatórios ausentes** (date, start_time, end_time, workTime)
   - Log: `⚠️ {campo} não encontrado no timeAppointment`
   - Ação: Não cria o apontamento

### Erros com Rollback

Se ocorrer erro durante a criação do timesheet:
- Transação é revertida (rollback)
- Erro completo é registrado em log
- Webhook retorna sucesso (status 200) para não reprocessar

## 📋 Checklist de Implementação

- [x] Importar models necessários (User, Customer, Project, Timesheet, SystemSetting)
- [x] Importar Carbon para manipulação de datas
- [x] Implementar função `processTicketActions()`
- [x] Implementar extração de user_id com validação
- [x] Implementar extração de customer_id com lógica de fallback
- [x] Implementar extração de project_id com validação
- [x] Implementar extração e formatação de date
- [x] Implementar extração e formatação de start_time
- [x] Implementar extração e formatação de end_time
- [x] Implementar extração e formatação de effort_hours
- [x] Implementar cálculo de effort_minutes
- [x] Implementar construção de observation
- [x] Implementar criação de timesheet com validação de duplicados
- [x] Implementar logs detalhados em todos os passos
- [x] Implementar tratamento de erros com rollback

## 🧪 Como Testar

### 1. Configurar SystemSettings

```bash
docker-compose exec app php artisan tinker

# No tinker:
\App\Models\SystemSetting::set('movidesk_default_customer_id', 1, 'integer', 'movidesk');
\App\Models\SystemSetting::set('movidesk_default_project_id', 1, 'integer', 'movidesk');
```

### 2. Simular Webhook

Use o arquivo `full_ticket_movidesk.json` como payload de teste:

```bash
curl -X POST http://localhost:8000/api/v1/movidesk/webhook/ticket \
  -H "Content-Type: application/json" \
  -d @app/Http/Controllers/full_ticket_movidesk.json
```

### 3. Verificar Logs

```bash
docker-compose exec app tail -f storage/logs/laravel.log
```

### 4. Verificar Timesheet Criado

```bash
docker-compose exec app php artisan tinker

# No tinker:
\App\Models\Timesheet::latest()->first();
```

## 📚 Referências

- **Controller**: `app/Http/Controllers/MovideskWebhookController.php`
- **JSON de Exemplo**: `storage/docs/movidesk/exemplo_full_ticket.json`
- **Rota**: `routes/api.php` → `/api/v1/movidesk/webhook/ticket`
- **Documentação Completa**: `MOVIDESK_WEBHOOK_INTEGRATION.md`
- **Guia de Testes**: `TESTE_WEBHOOK_MOVIDESK.md`
- **Documentação Movidesk API**: https://api.movidesk.com/public/v1/

## 🚀 Melhorias Futuras (Sugestões)

1. **Processar múltiplas ações**: Atualmente processa apenas `actions[0]`
2. **Processar múltiplos timeAppointments**: Atualmente processa apenas `timeAppointments[0]`
3. **Webhook de atualização**: Atualizar timesheet existente quando ticket for modificado
4. **Notificações**: Enviar email/notificação ao usuário quando timesheet for criado
5. **Dashboard**: Painel para visualizar timesheets criados via webhook
6. **Retry automático**: Reprocessar webhooks que falharam

---

**Implementado em**: Dezembro 2025  
**Versão**: 1.0  
**Autor**: Sistema Time Conect
