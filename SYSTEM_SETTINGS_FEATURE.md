# ⚙️ Sistema de Configurações - Feature Completa

## 📋 Visão Geral

Feature completa para gerenciamento de configurações do sistema Time Conect, permitindo que administradores configurem diversos parâmetros operacionais através de uma interface web amigável.

**Data de Implementação:** 24/11/2025  
**Status:** ✅ Implementado e Testado

---

## 🎯 Funcionalidades Implementadas

### 1. **Configuração de Apontamento de Horas**
- ✅ Prazo limite para lançamento retroativo de horas
- ✅ Campo: `timesheet_retroactive_limit_days`
- ✅ Tipo: Integer (0-365 dias)
- ✅ Valor padrão: 7 dias
- ✅ Exemplo prático com cálculo de data limite

### 2. **Sistema de Cache**
- ✅ Cache automático de configurações (1 hora)
- ✅ Invalidação automática ao atualizar
- ✅ Performance otimizada para leitura

### 3. **Interface Amigável**
- ✅ Formulário único com todas as configurações
- ✅ Validações em tempo real
- ✅ Exemplos práticos de uso
- ✅ Loading states e feedback visual

---

## 🏗️ Arquitetura

### Backend (Laravel)

#### 1. **Database Schema**

**Tabela:** `system_settings`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint | Chave primária |
| `key` | string(255) | Chave única da configuração |
| `value` | text | Valor da configuração |
| `type` | string(255) | Tipo: string, integer, boolean, json |
| `group` | string(255) | Grupo: general, timesheets, expenses, projects |
| `description` | text | Descrição da configuração |
| `created_at` | timestamp | Data de criação |
| `updated_at` | timestamp | Data de atualização |

**Índices:**
- `key` (único)
- `group`

#### 2. **Model: SystemSetting**

**Localização:** `app/Models/SystemSetting.php`

**Métodos Principais:**
```php
// Buscar configuração
SystemSetting::get('timesheet_retroactive_limit_days', 7);

// Definir configuração
SystemSetting::set('timesheet_retroactive_limit_days', 7, 'integer', 'timesheets');

// Buscar grupo inteiro
SystemSetting::getGroup('timesheets');

// Atualizar múltiplas
SystemSetting::setMultiple([
    'timesheet_retroactive_limit_days' => 7,
    'other_setting' => 'value'
]);

// Limpar cache
SystemSetting::clearCache();
```

**Features:**
- ✅ Cache automático (1 hora)
- ✅ Type casting (integer, boolean, json, string)
- ✅ Scopes para filtros
- ✅ Mass assignment protection

#### 3. **Controller: SystemSettingController**

**Localização:** `app/Http/Controllers/SystemSettingController.php`

**Endpoints:**

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/api/v1/system-settings` | Lista todas as configurações |
| `GET` | `/api/v1/system-settings/{key}` | Busca configuração específica |
| `PUT` | `/api/v1/system-settings` | Atualiza configurações |

**Permissões:**
- `system_settings.view` - Visualizar configurações
- `system_settings.update` - Atualizar configurações
- `admin.full_access` - Acesso completo

**Validações:**
```php
'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365'
```

#### 4. **Migration**

**Arquivo:** `database/migrations/2025_11_24_200000_create_system_settings_table.php`

```bash
# Executar migration
docker compose exec app php artisan migrate
```

#### 5. **Seeder**

**Arquivo:** `database/seeders/SystemSettingSeeder.php`

**Valores Padrão:**
- `timesheet_retroactive_limit_days`: 7 dias

```bash
# Executar seeder
docker compose exec app php artisan db:seed --class=SystemSettingSeeder
```

#### 6. **Rotas da API**

**Arquivo:** `routes/api.php`

```php
// Protegido por Sanctum + Permissões
Route::middleware('auth:sanctum')->group(function () {
    // Visualizar
    Route::middleware('permission.or.admin:system_settings.view')->group(function () {
        Route::get('/system-settings', [SystemSettingController::class, 'index']);
        Route::get('/system-settings/{key}', [SystemSettingController::class, 'show']);
    });

    // Atualizar
    Route::middleware('permission.or.admin:system_settings.update')->group(function () {
        Route::put('/system-settings', [SystemSettingController::class, 'update']);
    });
});
```

---

### Frontend (Angular 19)

#### 1. **Interface TypeScript**

**Arquivo:** `src/app/models/system-settings.interface.ts`

```typescript
export interface ISystemSettings {
  general?: { [key: string]: ISystemSettingValue };
  timesheets?: { [key: string]: ISystemSettingValue };
  expenses?: { [key: string]: ISystemSettingValue };
  projects?: { [key: string]: ISystemSettingValue };
}

export interface ISystemSettingValue {
  value: any;
  type: 'string' | 'integer' | 'boolean' | 'json';
  description?: string;
}

export interface ISystemSettingsForm {
  timesheet_retroactive_limit_days: number | null;
}
```

#### 2. **Service**

**Arquivo:** `src/app/core/services/system-settings.service.ts`

```typescript
@Injectable({ providedIn: 'root' })
export class SystemSettingsService {
  // Buscar todas
  getAll(): Observable<ISystemSettings>

  // Buscar por chave
  getByKey(key: string): Observable<any>

  // Atualizar
  update(settings: Partial<ISystemSettingsForm>): Observable<ISystemSettingsResponse>
}
```

#### 3. **Component**

**Arquivo:** `src/app/features/system-settings/system-settings.component.ts`

**Features:**
- ✅ Formulário reativo com validações
- ✅ Loading states (carregamento e salvamento)
- ✅ Exemplo prático com cálculo de datas
- ✅ Feedback visual (notificações)
- ✅ Ações de página (botão Salvar)
- ✅ Design responsivo

**Template:**
- `po-page-default` - Layout padrão
- `po-number` - Campo numérico com validações
- `po-divider` - Divisor de seções
- `po-info` - Informações contextuais
- `po-loading` - Estados de carregamento

#### 4. **Rota**

**Arquivo:** `src/app/app.routes.ts`

```typescript
{
  path: 'system-settings',
  loadComponent: () => import('./features/system-settings/system-settings.component')
    .then(m => m.SystemSettingsComponent),
  title: 'Configurações do Sistema - Time Conect',
  canActivate: [PermissionGuard],
  data: { permissions: ['system_settings.view', 'admin.full_access'] }
}
```

---

## 🔐 Segurança

### Permissões Implementadas

**Backend:**
```php
'system_settings.view'   // Visualizar configurações
'system_settings.update' // Atualizar configurações
'admin.full_access'      // Acesso total (bypass)
```

**Frontend:**
- Guard: `PermissionGuard`
- Rota protegida por permissão
- Apenas usuários autorizados

### Validações

**Campo `timesheet_retroactive_limit_days`:**
- ✅ Tipo: Integer
- ✅ Mínimo: 0 (sem limite retroativo)
- ✅ Máximo: 365 (1 ano)
- ✅ Obrigatório

---

## 📊 Como Usar

### 1. **Acesso ao Menu**

Navegue para: **Administração > Configurações do Sistema**

URL: `http://localhost:4200/system-settings`

### 2. **Configurar Prazo de Apontamentos**

**Cenário 1: Limite de 7 dias (padrão)**
```
Campo: 7
Resultado: Consultor pode lançar horas até 7 dias após a data do serviço

Exemplo:
- Serviço: 10/01/2025
- Prazo: até 17/01/2025 23:59
```

**Cenário 2: Sem limite**
```
Campo: 0
Resultado: Consultor pode lançar horas de qualquer data no passado
⚠️ Não recomendado para auditoria
```

**Cenário 3: Limite de 1 dia**
```
Campo: 1
Resultado: Consultor deve lançar no dia seguinte ao serviço

Exemplo:
- Serviço: 10/01/2025
- Prazo: até 11/01/2025 23:59
```

### 3. **Salvar Configurações**

1. Ajuste o valor desejado
2. Clique em **Salvar**
3. ✅ Notificação: "Configurações atualizadas com sucesso!"

---

## 🧪 Testes

### Teste Manual - Backend

```bash
# 1. Listar configurações
curl -X GET http://localhost:8000/api/v1/system-settings \
  -H "Authorization: Bearer {token}"

# 2. Buscar configuração específica
curl -X GET http://localhost:8000/api/v1/system-settings/timesheet_retroactive_limit_days \
  -H "Authorization: Bearer {token}"

# 3. Atualizar configurações
curl -X PUT http://localhost:8000/api/v1/system-settings \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"timesheet_retroactive_limit_days": 14}'
```

### Teste Manual - Frontend

1. ✅ Login como administrador
2. ✅ Navegar para Configurações do Sistema
3. ✅ Verificar carregamento do valor padrão (7 dias)
4. ✅ Alterar valor para 14 dias
5. ✅ Verificar exemplo prático atualizado
6. ✅ Salvar
7. ✅ Recarregar página e verificar persistência

---

## 🚀 Deploy

### 1. **Backend**

```bash
# 1. Entrar no container
docker compose exec app bash

# 2. Executar migrations
php artisan migrate

# 3. Executar seeders
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=SystemSettingSeeder

# 4. Limpar caches
php artisan config:clear
php artisan cache:clear
```

### 2. **Frontend**

```bash
# Build de produção
npm run build

# Deploy
# (seguir processo de deploy do projeto)
```

---

## 🔄 Como Adicionar Novas Configurações

### 1. **Backend - Adicionar Validação**

**Arquivo:** `app/Http/Controllers/SystemSettingController.php`

```php
// Método update() - Adicionar regra de validação
'new_setting_key' => 'nullable|string|max:255',

// Método getSettingType()
'new_setting_key' => 'string',

// Método getSettingGroup()
'new_setting_key' => 'general',

// Método getSettingDescription()
'new_setting_key' => 'Descrição da nova configuração',
```

### 2. **Backend - Atualizar Seeder**

**Arquivo:** `database/seeders/SystemSettingSeeder.php`

```php
SystemSetting::set(
    key: 'new_setting_key',
    value: 'default_value',
    type: 'string',
    group: 'general',
    description: 'Descrição da nova configuração'
);
```

### 3. **Frontend - Atualizar Interface**

**Arquivo:** `src/app/models/system-settings.interface.ts`

```typescript
export interface ISystemSettingsForm {
  timesheet_retroactive_limit_days: number | null;
  new_setting_key: string | null; // Nova configuração
}
```

### 4. **Frontend - Atualizar Component**

**Arquivo:** `src/app/features/system-settings/system-settings.component.ts`

```typescript
// Adicionar no FormGroup
this.settingsForm = this.fb.group({
  timesheet_retroactive_limit_days: [7, [...]],
  new_setting_key: ['default', [...]] // Nova configuração
});

// Adicionar no template
<po-input
  name="new_setting_key"
  p-label="Nova Configuração"
  formControlName="new_setting_key">
</po-input>
```

---

## 📝 Notas Técnicas

### Cache Strategy
- **Duração:** 1 hora (3600 segundos)
- **Invalidação:** Automática ao atualizar
- **Método:** Laravel Cache (`Cache::remember`)

### Performance
- ✅ Leituras cacheadas (rápidas)
- ✅ Escritas com invalidação automática
- ✅ Queries otimizadas com índices

### Escalabilidade
- ✅ Sistema preparado para múltiplas configurações
- ✅ Organizado por grupos (timesheets, expenses, projects, general)
- ✅ Fácil adição de novas configurações

---

## 🎨 Screenshots

### Tela de Configurações
```
╔══════════════════════════════════════════════════════════╗
║  Configurações do Sistema                      [Salvar]  ║
╠══════════════════════════════════════════════════════════╣
║                                                          ║
║  ═══ Apontamento de Horas ═══                           ║
║                                                          ║
║  ℹ️ Configure o período máximo que consultores podem     ║
║     lançar horas retroativamente no sistema.            ║
║                                                          ║
║  Prazo para lançamento de apontamentos (dias)           ║
║  ┌─────────────────────────────────────────────────┐    ║
║  │ 7                                               │    ║
║  └─────────────────────────────────────────────────┘    ║
║  Quantidade de dias após a data do serviço que o        ║
║  consultor pode lançar horas. Use 0 para desabilitar.   ║
║                                                          ║
║  📌 Exemplo prático:                                     ║
║  ✅ Limite de 7 dias: Se o serviço foi realizado em     ║
║     10/01/2025, o consultor pode lançar até             ║
║     17/01/2025 às 23:59.                                ║
║                                                          ║
╚══════════════════════════════════════════════════════════╝
```

---

## ✅ Checklist de Implementação

- [x] Migration criada e executada
- [x] Model com cache implementado
- [x] Controller com validações
- [x] Permissões configuradas
- [x] Rotas da API definidas
- [x] Seeder com valores padrão
- [x] Interface TypeScript
- [x] Service Angular
- [x] Component com formulário
- [x] Rota protegida
- [x] Validações frontend
- [x] Exemplos práticos
- [x] Documentação completa
- [x] Testes manuais realizados

---

## 🎯 Próximos Passos Sugeridos

1. **Novas Configurações:**
   - Prazo limite para despesas
   - Categorias de despesas obrigatórias
   - Campos customizados obrigatórios por contexto
   - Formatos de exportação permitidos

2. **Melhorias:**
   - Histórico de alterações de configurações
   - Auditoria de mudanças
   - Notificações aos usuários quando configurações mudam
   - Configurações por tenant (multi-tenancy)

3. **Validações Avançadas:**
   - Validações dependentes entre configurações
   - Regras de negócio complexas
   - Alertas de impacto ao mudar configurações críticas

---

**Feature implementada com sucesso! 🚀**

