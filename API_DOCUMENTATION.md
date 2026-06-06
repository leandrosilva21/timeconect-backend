# 🔐 API Time Conect - Sistema de Apontamento de Horas

## Visão Geral

Esta API implementa um sistema completo de apontamento de horas com autenticação segura usando Laravel Sanctum, gerenciamento de usuários, projetos e sistema de aprovação de horas trabalhadas.

## Base URL
```
http://localhost:8000/api/v1
```

## Headers Obrigatórios

Para todas as requisições:
```
Content-Type: application/json
Accept: application/json
```

Para rotas protegidas:
```
Authorization: Bearer {token}
```

---

## 📋 Principais Endpoints

### 🔐 Autenticação
- `POST /api/v1/auth/login` - Login de usuário
- `POST /api/v1/auth/logout` - Logout do usuário atual  
- `GET /api/v1/auth/verify-token` - Verificar token válido
- `POST /api/v1/auth/change-password` - Alterar senha

### 👥 Usuários e Permissões
- `GET /api/v1/users` - Listar usuários
- `GET /api/v1/roles` - Listar perfis
- `GET /api/v1/permissions` - Listar permissões

### 🏢 Clientes
- `GET /api/v1/customers` - Listar clientes
- `POST /api/v1/customers` - Criar cliente
- `PUT /api/v1/customers/{id}` - Atualizar cliente

### 🏗️ Projetos  
- `GET /api/v1/projects` - Listar projetos
- `POST /api/v1/projects` - Criar projeto
- `PUT /api/v1/projects/{id}` - Atualizar projeto

### ⏰ Apontamento de Horas
- `GET /api/v1/timesheets` - Listar apontamentos
- `POST /api/v1/timesheets` - Criar apontamento
- `GET /api/v1/timesheets/{id}` - Visualizar apontamento
- `PUT /api/v1/timesheets/{id}` - Atualizar apontamento
- `DELETE /api/v1/timesheets/{id}` - Excluir apontamento
- `POST /api/v1/timesheets/{id}/approve` - Aprovar apontamento
- `POST /api/v1/timesheets/{id}/reject` - Rejeitar apontamento

---

## 🚀 Endpoints Públicos

### 1. Login
**POST** `/v1/auth/login`

Autentica um usuário e retorna token de acesso.

**Body:**
```json
{
  "email": "admin@timeconect.com",
  "password": "admin123456",
  "device_name": "web-app" // opcional
}
```

**Resposta (200):**
```json
{
  "message": "Login realizado com sucesso",
  "user": {
    "id": 1,
    "name": "Administrador",
    "email": "admin@timeconect.com",
    "email_verified_at": "2024-01-01T00:00:00.000000Z"
  },
  "token": "1|abc123...",
  "token_type": "Bearer"
}
```

### 2. Esqueci Minha Senha
**POST** `/v1/auth/forgot-password`

Envia link de recuperação de senha por email.

**Body:**
```json
{
  "email": "admin@timeconect.com"
}
```

**Resposta (200):**
```json
{
  "message": "Link de recuperação enviado para seu email"
}
```

### 3. Resetar Senha
**POST** `/v1/auth/reset-password`

Redefine a senha usando token recebido por email.

**Body:**
```json
{
  "email": "admin@timeconect.com",
  "token": "reset_token_from_email",
  "password": "nova_senha_123",
  "password_confirmation": "nova_senha_123"
}
```

**Resposta (200):**
```json
{
  "message": "Senha redefinida com sucesso"
}
```

### 4. Verificar Token de Reset
**POST** `/v1/auth/verify-reset-token`

Verifica se token de reset é válido.

**Body:**
```json
{
  "email": "admin@timeconect.com",
  "token": "reset_token_from_email"
}
```

**Resposta (200):**
```json
{
  "message": "Token válido",
  "valid": true
}
```

---

## 🔒 Endpoints Protegidos

### 5. Dados do Usuário
**GET** `/v1/user`

Retorna dados do usuário autenticado.

**Resposta (200):**
```json
{
  "user": {
    "id": 1,
    "name": "Administrador",
    "email": "admin@timeconect.com",
    "email_verified_at": "2024-01-01T00:00:00.000000Z",
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### 6. Atualizar Perfil
**PUT** `/v1/user/profile`

Atualiza dados do perfil do usuário.

**Body:**
```json
{
  "name": "Novo Nome",
  "email": "novo@email.com"
}
```

**Resposta (200):**
```json
{
  "message": "Perfil atualizado com sucesso",
  "user": {
    "id": 1,
    "name": "Novo Nome",
    "email": "novo@email.com",
    "email_verified_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

### 7. Alterar Senha
**POST** `/v1/auth/change-password`

Altera a senha do usuário autenticado.

**Body:**
```json
{
  "current_password": "senha_atual",
  "new_password": "nova_senha_123",
  "new_password_confirmation": "nova_senha_123"
}
```

**Resposta (200):**
```json
{
  "message": "Senha alterada com sucesso. Faça login novamente."
}
```

### 8. Logout
**POST** `/v1/auth/logout`

Desconecta o usuário (revoga token atual).

**Resposta (200):**
```json
{
  "message": "Logout realizado com sucesso"
}
```

### 9. Logout de Todos os Dispositivos
**POST** `/v1/auth/logout-all`

Desconecta o usuário de todos os dispositivos.

**Resposta (200):**
```json
{
  "message": "Logout realizado em todos os dispositivos"
}
```

### 10. Verificar Token
**GET** `/v1/auth/verify-token`

Verifica se token atual é válido.

**Resposta (200):**
```json
{
  "message": "Token válido",
  "valid": true,
  "user": {
    "id": 1,
    "name": "Administrador",
    "email": "admin@timeconect.com"
  }
}
```

---

## 🏥 Health Check

### Status da API
**GET** `/v1/health`

Verifica se a API está funcionando.

**Resposta (200):**
```json
{
  "status": "ok",
  "message": "API funcionando corretamente",
  "timestamp": "2024-01-01T00:00:00.000000Z",
  "version": "1.0.0"
}
```

---

## 🔐 Segurança Implementada

### Rate Limiting
- **Login**: 5 tentativas por minuto
- **Forgot Password**: 3 tentativas por hora
- **Reset Password**: 5 tentativas por hora

### Validações
- Todas as entradas são validadas e sanitizadas
- Senhas têm mínimo de 8 caracteres
- Emails devem ser válidos e únicos

### Headers de Segurança
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy: default-src 'self'`

### Outras Medidas
- Tokens são revogados automaticamente em operações sensíveis
- Senhas são criptografadas com bcrypt
- Rate limiting por IP
- Logs de segurança automáticos

---

## 👥 Usuários de Teste

### Credenciais Padrão
```
Admin: admin@timeconect.com / admin123456
Teste: teste@timeconect.com / teste123456
Demo: demo@timeconect.com / demo123456
```

---

## ⏰ Apontamento de Horas - Exemplos

### 1. Listar Apontamentos
**GET** `/api/v1/timesheets?page=1&pageSize=10&order=-date&project_id=1`

**Resposta PO-UI:**
```json
{
  "hasNext": true,
  "items": [
    {
      "id": 1,
      "date": "2024-01-15",
      "start_time": "09:00",
      "end_time": "17:00",
      "effort_hours": "8:00",
      "status": "pending",
      "status_display": "Pendente",
      "observation": "Desenvolvimento de API",
      "ticket": "TICKET-123",
      "user": {
        "name": "João Silva"
      },
      "project": {
        "name": "Sistema ERP"
      }
    }
  ]
}
```

### 2. Criar Apontamento
**POST** `/api/v1/timesheets`
```json
{
  "project_id": 1,
  "date": "2024-01-15",
  "start_time": "09:00",
  "end_time": "17:00",
  "observation": "Desenvolvimento de funcionalidade X",
  "ticket": "TICKET-123"
}
```

### 3. Aprovar/Rejeitar
**POST** `/api/v1/timesheets/1/approve` - Aprovação
**POST** `/api/v1/timesheets/1/reject` - Rejeição (requer motivo)

### 4. Permissões de Timesheet
- `hours.view` - Ver apontamentos próprios
- `hours.create` - Criar apontamentos
- `hours.update_own` - Editar próprios apontamentos
- `hours.approve` - Aprovar apontamentos (aprovadores do projeto)
- `hours.reject` - Rejeitar apontamentos

---

## 🚨 Códigos de Erro

| Código | Descrição |
|--------|-----------|
| 400 | Requisição inválida |
| 401 | Não autorizado |
| 422 | Dados de validação inválidos |
| 429 | Muitas tentativas (rate limit) |
| 500 | Erro interno do servidor |

---

## 💡 Exemplo de Uso

### 1. Fazer Login
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "admin@timeconect.com",
    "password": "admin123456"
  }'
```

### 2. Usar Token para Acessar Dados
```bash
curl -X GET http://localhost:8000/api/user \
  -H "Authorization: Bearer 1|abc123..." \
  -H "Accept: application/json"
```

### 3. Fazer Logout
```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer 1|abc123..." \
  -H "Accept: application/json"
``` 