# CLAUDE.md — Time Conect Backend (Laravel)

> Referência rápida para o agente. Fonte de verdade completa: `/Users/ricardodeoliveirasilva/Documents/Obsidian Vault/TIME CONECT.md`

---

## Stack

- **Laravel 10** + PHP 8.2
- **PostgreSQL** (auto-hospedado no VPS de produção)
- **Deploy:** três ambientes — ver tabela abaixo

## Ambientes

| Ambiente | Frontend | Backend | Obs |
|---|---|---|---|
| **Homolog** | Render | Render | Repos `ricardooliveiraerpserv/*-homolog` |
| **Produção** | `app.timeconect.com.br` | `api.timeconect.com.br` | VPS 178.18.246.162, Docker Compose, Cloudflare Tunnel |
| **Produção antiga** | Render (inativo) | Render (inativo) | — |

### Deploy em produção (VPS)
```bash
# No servidor (SSH fabio@178.18.246.162)
git -C /opt/timeconect/backend pull origin main
git -C /opt/timeconect/frontend pull origin main
docker build -t timeconect-backend:latest /opt/timeconect/backend
docker build --build-arg NEXT_PUBLIC_API_URL=https://api.timeconect.com.br -t timeconect-frontend:latest /opt/timeconect/frontend
cd /opt/timeconect && docker compose up -d --no-deps backend queue-worker scheduler frontend
docker exec timeconect-backend php artisan migrate --force
```

---

## Regras críticas

### Autenticação e perfis
- Perfis em `users.type`: `admin | coordenador | consultor | cliente | parceiro_admin`
- **Nunca usar** `hasRole()` / Spatie — substituído por `isAdmin()`, `isCoordenador()` etc. no User model
- Permissões via `PermissionService.php` (arrays PHP puros, zero banco)

### Migrations
- `CREATE INDEX CONCURRENTLY` exige `public $withinTransaction = false;`
- Sempre usar `IF NOT EXISTS` para idempotência
- Após deploy no Render: rodar `php artisan migrate` no Render Shell

### PDO / PostgreSQL
- **Não usar** `PDO::ATTR_EMULATE_PREPARES => true` — causa bug com colunas boolean (converte `true` → `1`, PG rejeita sem cast)
- **Não usar** `PDO::ATTR_PERSISTENT => true` — prepared statements se perdem no pool do PHP-FPM

### N+1 no Model
```php
// ERRADO
$this->hourContributions()->sum('hours')   // nova query sempre

// CERTO
$contributions = $this->relationLoaded('hourContributions')
    ? $this->hourContributions
    : $this->hourContributions()->get();
$total = $contributions->sum('hours');
```

---

## Contratos — regras de negócio

### Exclusão de contrato (`DELETE /contracts/{id}`)
Bloqueado se:
1. O contrato tiver `project_id` E o projeto vinculado tiver `Expense` registrada
2. O contrato tiver `project_id` E o projeto vinculado tiver `Timesheet` registrada

Mensagem 422 diferente por caso. **Não bloquear** por kanban logs (regra anterior — revogada em 2026-04-23).

### Kanban de Contratos (`kanbanMove`)
- `inicio_autorizado` → atualiza `status` E `kanban_status`
- Colunas de sustentação → `sustentacaoMove` (endpoint separado)
- `demand_cards` inclui `inicio_autorizado` e `alocado` para evitar cards invisíveis

### Pipeline — `requestPlanDecision`
```php
$toColumn = $decision === 'novo_projeto' ? 'req_inicio_autorizado' : 'inicio_autorizado';
```

### Categoria de contrato
- `categoria = 'sustentacao'` → serviceType.name contém "cloud", "bizify", "sustentacao"
- `categoria = 'projeto'` → todos os demais

---

## Fechamento — regra de status
Todos os 4 controllers de fechamento incluem `pending` e `conflicted`, excluem `adjustment_requested` e `rejected`:
```php
->whereNotIn('status', ['adjustment_requested', 'rejected'])
```

---

## Consultant types
| Valor | Comportamento |
|---|---|
| `horista` | Pago por hora, tem extras |
| `bh_fixo` | Banco de horas fixo, sem extras |
| `bh_mensal` | Banco de horas mensal, com extras |
| `fixo` | Fixo puro, sem banco de horas |

Validação: `'consultant_type' => 'nullable|in:horista,bh_fixo,bh_mensal,fixo'`

---

## Arquivos-chave

| Arquivo | Responsabilidade |
|---|---|
| `app/Http/Controllers/ContractController.php` | CRUD contratos, kanbanMove, sustentacaoMove, requestPlanDecision |
| `app/Http/Controllers/FechamentoController.php` | Fechamento administrativo |
| `app/Http/Controllers/FechamentoClienteController.php` | Fechamento On Demand por cliente |
| `app/Http/Controllers/FechamentoConsultorController.php` | Fechamento por consultor |
| `app/Http/Controllers/FechamentoParceiroController.php` | Fechamento por parceiro |
| `app/Models/Contract.php` | Constants KANBAN_*, DEMAND_COLUMNS, isKanbanComplete() |
| `app/Services/PermissionService.php` | Permissões por perfil (arrays PHP) |
| `app/Services/HourBankService.php` | Cálculo de banco de horas e proporcionalidade |
| `routes/api.php` | Todas as rotas da API |
