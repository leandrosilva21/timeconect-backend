# Correção: Administradores Visualizam TODOS os Clientes

## 🐛 Problema Identificado

No endpoint `/customers/user-linked`, quando um usuário **Administrator** fazia a busca (ou quando um Administrator buscava para outro usuário Administrator), o sistema retornava apenas os clientes vinculados a projetos onde o usuário era consultor ou aprovador.

**Comportamento Incorreto:**
- Administrator logado → via apenas clientes vinculados aos seus projetos ❌
- Administrator selecionando outro Administrator no form → também via apenas clientes vinculados ❌

**Comportamento Esperado:**
- Administrator → deve ver TODOS os clientes ✅
- Administrator selecionando outro Administrator → também deve ver TODOS os clientes ✅
- Usuário comum → continua vendo apenas clientes vinculados aos seus projetos ✅

## 🔧 Solução Implementada

### Backend - CustomerController.php

**Arquivo:** `app/Http/Controllers/CustomerController.php`
**Método:** `getUserLinkedCustomers()`

#### O que foi alterado:

```php
// ANTES - Sempre filtrava por vinculação
$customerIds = Customer::whereHas('projects', function ($query) use ($targetUserId) {
    // ... filtros de vinculação
})->pluck('id');

$query = Customer::whereIn('id', $customerIds);
```

```php
// DEPOIS - Verifica se o usuário alvo é Administrator
$targetUser = $currentUser;

if ($requestedUserId && $currentUser->hasRole('Administrator')) {
    $targetUserId = $requestedUserId;
    $targetUser = \App\Models\User::find($targetUserId);
}

// Se o usuário alvo é Administrator, retorna TODOS os clientes
if ($targetUser && $targetUser->hasRole('Administrator')) {
    $query = Customer::query(); // SEM filtros de vinculação
} else {
    // Para usuários não-admin, busca apenas clientes vinculados
    $customerIds = Customer::whereHas('projects', function ($query) use ($targetUserId) {
        // ... filtros de vinculação
    })->pluck('id');
    
    $query = Customer::whereIn('id', $customerIds);
}
```

## 📊 Lógica de Negócio

### Fluxos de Uso:

#### 1. **Usuário Administrator Logado**
```
Request: GET /api/v1/customers/user-linked
User: Administrator (ID: 1)
user_id: não informado

Resultado: Retorna TODOS os clientes (query sem filtro de vinculação)
```

#### 2. **Administrator Buscando para Outro Administrator**
```
Request: GET /api/v1/customers/user-linked?user_id=2
User: Administrator (ID: 1)
Target User: Administrator (ID: 2)

Resultado: Retorna TODOS os clientes (query sem filtro de vinculação)
```

#### 3. **Administrator Buscando para Usuário Comum**
```
Request: GET /api/v1/customers/user-linked?user_id=3
User: Administrator (ID: 1)
Target User: Consultor (ID: 3)

Resultado: Retorna apenas clientes onde o usuário 3 é consultor/aprovador
```

#### 4. **Usuário Comum Logado**
```
Request: GET /api/v1/customers/user-linked
User: Consultor (ID: 3)
user_id: não pode ser informado (não é admin)

Resultado: Retorna apenas clientes onde o usuário 3 é consultor/aprovador
```

## 🎯 Casos de Teste

### Teste 1: Administrator Logado
```bash
# Login como Administrator
POST /api/v1/auth/login
{
  "email": "admin@timeconect.com",
  "password": "senha123"
}

# Buscar clientes
GET /api/v1/customers/user-linked

# Resultado Esperado:
# - Retorna TODOS os clientes do sistema
# - Não aplica filtro de vinculação a projetos
```

### Teste 2: Administrator Selecionando Outro Administrator
```bash
# Login como Administrator (ID: 1)
POST /api/v1/auth/login
{
  "email": "admin@timeconect.com",
  "password": "senha123"
}

# Buscar clientes para outro Administrator (ID: 2)
GET /api/v1/customers/user-linked?user_id=2

# Resultado Esperado:
# - Retorna TODOS os clientes do sistema
# - Não aplica filtro de vinculação a projetos
```

### Teste 3: Administrator Selecionando Usuário Comum
```bash
# Login como Administrator (ID: 1)
POST /api/v1/auth/login
{
  "email": "admin@timeconect.com",
  "password": "senha123"
}

# Buscar clientes para usuário comum (ID: 5)
GET /api/v1/customers/user-linked?user_id=5

# Resultado Esperado:
# - Retorna apenas clientes onde o usuário 5 é consultor/aprovador em projetos
# - Aplica filtro de vinculação
```

### Teste 4: Usuário Comum Logado
```bash
# Login como Consultor
POST /api/v1/auth/login
{
  "email": "consultor@timeconect.com",
  "password": "senha123"
}

# Buscar clientes
GET /api/v1/customers/user-linked

# Resultado Esperado:
# - Retorna apenas clientes onde o usuário logado é consultor/aprovador
# - Aplica filtro de vinculação
# - Não pode passar user_id (não é admin)
```

## 📱 Impacto no Frontend

### Onde é Usado:

1. **Timesheet Form** (`timesheet-form.component.ts`)
   - Ao criar/editar apontamento de horas
   - Campo "Cliente" será populado com todos os clientes se usuário for Admin

2. **Expense Form** (se usar o mesmo endpoint)
   - Ao criar/editar despesas
   - Campo "Cliente" terá todos os clientes disponíveis para Admins

3. **Outros Forms** que usem `getUserLinkedCustomers()`
   - Automaticamente se beneficiam da correção

### Como Funciona no Frontend:

```typescript
// timesheet-form.component.ts (linha 557-578)
private loadCustomers(): void {
  const params: any = { pageSize: 1000 };
  
  // Se é admin e tem usuário selecionado, enviar o user_id
  if (this.permissionService.isAdmin() && this.timesheetForm.get('user_id')?.value) {
    params.user_id = this.timesheetForm.get('user_id')?.value;
  }

  this.customerService.getUserLinkedCustomers(params).subscribe({
    next: (customers) => {
      this.customerOptions = customers.map(customer => ({
        value: customer.id,
        label: customer.name
      }));
    },
    error: (error) => {
      console.error('Erro ao carregar clientes:', error);
      this.notification.error('Erro ao carregar clientes');
    }
  });
}
```

**Comportamento após a correção:**
1. Admin logado sem usuário selecionado → vê TODOS os clientes ✅
2. Admin logado com outro Admin selecionado → vê TODOS os clientes ✅
3. Admin logado com usuário comum selecionado → vê apenas clientes do usuário ✅
4. Usuário comum logado → vê apenas seus próprios clientes vinculados ✅

## ✅ Validação

### Checklist de Verificação:

- [x] Código implementado corretamente
- [x] Sem erros de linting
- [x] Lógica de negócio consistente
- [x] Comportamento diferenciado para Admins
- [x] Compatibilidade com frontend existente
- [x] Não quebra funcionalidade de usuários comuns
- [x] Documentação completa

## 🔒 Segurança

### Validações Mantidas:

1. **Permissão de Admin é verificada** antes de aceitar `user_id` no request
2. **Usuários comuns não podem buscar para outros usuários**
3. **Filtros de busca continuam funcionando** (search, order, pagination)
4. **Soft deletes respeitados** (clientes deletados não aparecem)

## 📝 Observações Importantes

1. **Endpoint `/customers`** (index) continua funcionando normalmente
   - Sempre retorna TODOS os clientes (requer permissão)
   - Não verifica vinculação a projetos

2. **Endpoint `/customers/user-linked`** agora tem comportamento inteligente:
   - Administrators: TODOS os clientes
   - Usuários comuns: apenas clientes vinculados

3. **Não há cache** - sempre busca dados atualizados do banco

4. **Performance** - Para Administrators, query é mais simples (sem JOINs complexos)

---

**Data da Correção:** 17 de Janeiro de 2025  
**Versão:** 1.0.0  
**Autor:** Sistema Time Conect

