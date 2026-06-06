# 🎯 Regras do Projeto Time Conect - Plataforma de Apontamento de Horas

## 📊 Sobre o Projeto Time Conect

**Time Conect** é uma plataforma completa para **apontamento de horas trabalhadas em projetos de clientes**.

### Funcionalidades Principais:
- ✅ **Gestão de Clientes** - Cadastro e gerenciamento de clientes (customers)
- ✅ **Projetos por Cliente** - Criação e acompanhamento de projetos
- ✅ **Apontamento de Horas** - Registro detalhado de horas trabalhadas
- ✅ **Controle de Equipe** - Gerenciamento de usuários e suas funções
- ✅ **Relatórios** - Relatórios de horas, custos e produtividade
- ✅ **Dashboard Analytics** - Visão analítica do trabalho realizado
- ✅ **Aprovação de Horas** - Workflow de aprovação de apontamentos
- ✅ **Gestão de Despesas** - Controle de gastos relacionados aos projetos

### Entidades Principais:
- **Users** - Usuários do sistema (consultores, gerentes, administradores)
- **Customers** - Clientes da empresa
- **Projects** - Projetos vinculados aos clientes
- **Hours** - Apontamentos de horas trabalhadas nos projetos
- **Expenses** - Despesas relacionadas aos projetos
- **Reports** - Relatórios gerados

### Sistema de Permissões:
O projeto utiliza um sistema robusto de roles e permissões:
- **Administrator** - Acesso total ao sistema
- **Project Manager** - Gerenciamento de projetos e aprovações
- **Consultant** - Apontamento de horas próprias e visualização limitada

## 🌐 Padronização de Idioma

**REGRA OBRIGATÓRIA:** Todas as entidades, campos/atributos e propriedades do banco de dados DEVEM ser nomeados em inglês.

### ✅ Padrão Correto:
- **Entidades:** `Customer`, `Project`, `User`, `Hour`
- **Atributos:** `name`, `email`, `password`, `created_at`, `status`
- **Relacionamentos:** `customers`, `projects`, `users`

### ❌ NÃO Use:
- **Português:** `nome` → use `name`
- **Português:** `email` está OK (universal)
- **Português:** `data_criacao` → use `created_at`

### Justificativa:
- **Consistência:** Mantém uniformidade no código
- **Padrão Internacional:** Facilita colaboração e documentação
- **Frameworks:** Laravel usa convenções em inglês
- **Futuro:** Facilita internacionalização

### Exemplos de Aplicação:
```php
// ✅ Correto
class Customer extends Model {
    protected $fillable = ['name', 'cgc'];
}

// ❌ Incorreto
class Customer extends Model {
    protected $fillable = ['nome', 'cgc'];
}
```

## 🔌 Projeto API-Only

**IMPORTANTE:** Este projeto Laravel é configurado exclusivamente como API REST.

### Características do Projeto:
- ✅ **Apenas rotas de API** (sem views/Blade)
- ✅ **Respostas JSON obrigatórias**
- ✅ **Autenticação via tokens** (Laravel Sanctum)
- ✅ **CORS configurado** para frontend separado
- ✅ **Rate limiting** para proteção
- ✅ **Documentação Swagger** obrigatória para todos os endpoints
- ✅ **Sistema de permissões com Spatie Permission**

### ❌ NÃO Use Neste Projeto:
- Views/Blade templates
- Sessões web tradicionais
- Middleware web (use 'api')
- Flash messages
- Redirects

## 🤖 Instruções para Assistente AI

### 🔌 CONTEXTO: PROJETO API-ONLY
**CRÍTICO:** Este é um projeto Laravel configurado exclusivamente como API REST.

#### Sempre Considerar:
- ✅ Respostas JSON obrigatórias
- ✅ Autenticação via tokens (Sanctum)
- ✅ Rotas em `routes/api.php`
- ✅ Middleware `api` (não `web`)
- ✅ Stateless (sem sessões)
- ✅ CORS para frontend separado
- ✅ **Padrões PO-UI obrigatórios**
- ✅ **Sistema de permissões Spatie**

#### NUNCA Sugerir:
- ❌ Views/Blade templates
- ❌ Sessões web
- ❌ Flash messages
- ❌ Redirects
- ❌ Middleware `web`

### 🐳 Comandos PHP Obrigatórios
**CRÍTICO:** Quando sugerir ou executar comandos PHP/Laravel, SEMPRE use o container:

```bash
# Template obrigatório para comandos PHP:
docker-compose exec app [COMANDO_PHP]
```

#### Exemplos Automáticos:
- `php artisan` → `docker-compose exec app php artisan`
- `composer` → `docker-compose exec app composer`
- `php script.php` → `docker-compose exec app php script.php`

### 🗄️ MySQL Context
Este projeto usa **MySQL 8.0** com as seguintes configurações:
- Container: `db`
- Database: `laravel`
- User: `laravel_user`
- Password: `laravel_password`

### 🔐 Código Seguro por Padrão
Ao gerar código Laravel API, SEMPRE incluir:

#### 1. Validação de Entrada:
```php
// Use Form Requests
class CreateUserRequest extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email|unique:users',
            'name' => 'required|string|max:255',
        ];
    }
}
```

#### 2. Queries Seguras:
```php
// SEMPRE use Eloquent ou Query Builder
User::where('email', $email)->first();
// NUNCA concatenação SQL direta
```

#### 3. Middleware de Autenticação API:
```php
// Proteger rotas API automaticamente
Route::middleware('auth:sanctum')->group(function () {
    // rotas protegidas
});
```

#### 4. Sistema de Permissões:
```php
// Usar middleware de permissões
Route::middleware('permission.or.admin:customers.view')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
});
```

## 📖 Documentação de API com Swagger

**REGRA OBRIGATÓRIA:** Todo endpoint da API DEVE ter documentação Swagger completa.

### ✅ Requisitos de Documentação:

#### Para Todo Endpoint:
- ✅ **Anotação @OA\** completa
- ✅ **Tag apropriada** (Autenticação, Usuário, Customers, etc.)
- ✅ **Summary e description claros**
- ✅ **Exemplos de request/response**
- ✅ **Códigos de status documentados**
- ✅ **Validações de entrada documentadas**

#### Exemplo de Documentação Obrigatória:
```php
/**
 * @OA\Post(
 *     path="/api/v1/customers",
 *     tags={"Customers"},
 *     summary="Criar customer",
 *     description="Cria um novo customer no sistema",
 *     security={{"sanctum": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"nome","cgc"},
 *             @OA\Property(property="nome", type="string", example="João Silva"),
 *             @OA\Property(property="cgc", type="string", example="12345678901")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Customer criado com sucesso"),
 *     @OA\Response(response=422, description="Dados de validação inválidos")
 * )
 */
public function store(Request $request): JsonResponse
```

#### Tags Padrão do Projeto:
- **Autenticação** - Login, logout, verificação de tokens
- **Usuário** - Gerenciamento de dados do usuário
- **Customers** - Gerenciamento de clientes
- **Projects** - Gerenciamento de projetos
- **Hours** - Apontamento de horas
- **Expenses** - Controle de despesas
- **Reports** - Relatórios
- **Roles** - Gerenciamento de roles/perfis
- **Permissions** - Gerenciamento de permissões
- **Sistema** - Health check, status da API

#### Comandos de Documentação:
```bash
# Gerar documentação
docker-compose exec app php artisan l5-swagger:generate

# Acessar documentação
http://localhost:8000/api/documentation

# JSON da documentação
http://localhost:8000/docs
```

## 🐳 Execução de Comandos PHP

**REGRA OBRIGATÓRIA:** Todo comando PHP/Laravel deve ser executado dentro do container do PHP.

### ✅ Comandos Corretos:
```bash
# Comandos Artisan
docker-compose exec app php artisan migrate
docker-compose exec app php artisan make:model Customer
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan queue:work

# Composer
docker-compose exec app composer install
docker-compose exec app composer require package-name
docker-compose exec app composer dump-autoload

# PHP Scripts
docker-compose exec app php script.php

# Tinker
docker-compose exec app php artisan tinker

# Testes
docker-compose exec app php artisan test
docker-compose exec app ./vendor/bin/phpunit
```

### ❌ NUNCA Execute Localmente:
```bash
# NÃO FAÇA ISSO
php artisan migrate          # ❌ Vai usar PHP local
composer install            # ❌ Dependências podem ser incompatíveis
php script.php             # ❌ Configurações diferentes
```

## 🗄️ Banco de Dados MySQL

**CONFIGURAÇÃO:** Este projeto usa MySQL 8.0 como banco principal.

### Conexão do Laravel:
- **Host:** `db` (nome do container)
- **Database:** `laravel`
- **Username:** `laravel_user`
- **Password:** `laravel_password`
- **Port:** `3306`

### Comandos MySQL:
```bash
# Conectar ao MySQL
docker-compose exec db mysql -u laravel_user -p laravel

# Executar SQL direto
docker-compose exec db mysql -u laravel_user -p laravel -e "SHOW TABLES;"

# Backup
docker-compose exec db mysqldump -u laravel_user -p laravel > backup.sql

# Import
docker-compose exec -T db mysql -u laravel_user -p laravel < backup.sql
```

## 🌐 Padrões de API (Baseado em PO-UI Guidelines)

### Estrutura de Rotas:
- **SEMPRE** use `routes/api.php` para definir rotas
- Prefixo automático: `/api/`
- Middleware padrão: `api`
- **Suporte aos padrões PO-UI**: paginação, filtros e ordenação

```php
// ✅ Estrutura correta de rotas API com suporte PO-UI
Route::middleware('api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        // GET /api/customers?page=1&pageSize=20&order=nome,-created_at&status=active
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
    });
});
```

### 📄 Paginação (Padrão PO-UI)

**Parâmetros obrigatórios:**
- `page`: valor numérico (maior que zero) representando a página solicitada
- `pageSize`: valor numérico (maior que zero) representando o total de registros retornados

**Semântica de multiplicação:**
- `page=2` com `pageSize=20` deve retornar registros de 21 até 40

```bash
# Primeira página (página 1, 20 registros)
GET /api/customers?page=1&pageSize=20

# Segunda página (página 2, 20 registros) - registros 21 a 40
GET /api/customers?page=2&pageSize=20

# Exemplo completo com filtros e ordenação
GET /api/customers?page=1&pageSize=20&order=nome,-created_at&status=active
```

### 🔀 Ordenação (Padrão PO-UI)

**Parâmetro:** `order`

**Regras:**
- Campos **sem sinal** = ordenação **crescente** (ASC)
- Campos **com sinal de subtração (-)** = ordenação **decrescente** (DESC)
- Múltiplos campos separados por vírgula

```bash
# Ordenar por nome crescente
GET /api/customers?order=nome

# Ordenar por criação decrescente
GET /api/customers?order=-created_at

# Ordenação múltipla: nome crescente, criação decrescente
GET /api/customers?order=nome,-created_at
```

### 🔍 Filtros (Padrão PO-UI)

**Formato:** `property=value`

**Regras:**
- Filtros simples por igualdade
- Múltiplos filtros aplicam AND implícito
- Valores devem ser URL encoded quando necessário

```bash
# Filtro simples por nome
GET /api/customers?nome=João

# Múltiplos filtros (AND implícito)
GET /api/customers?nome=João&cgc=12345678901

# Exemplo com busca
GET /api/customers?search=Silva
```

### Respostas JSON Padronizadas (PO-UI):

**Para coleções (listas):**
```json
{
  "hasNext": true,
  "items": [
    {
      "id": 1,
      "nome": "João Silva",
      "cgc": "12345678901"
    },
    {
      "id": 2,
      "nome": "Maria Santos", 
      "cgc": "98765432109"
    }
  ]
}
```

**Para item único:**
```json
{
  "id": 1,
  "nome": "João Silva",
  "cgc": "12345678901",
  "formatted_cgc": "123.456.789-01",
  "cgc_type": "CPF",
  "created_at": "2024-01-15T10:30:00Z"
}
```

**Para item único com mensagens informativas:**
```json
{
  "id": 1,
  "nome": "João Silva",
  "cgc": "12345678901",
  "_messages": [{
    "code": "INFO",
    "type": "information",
    "message": "Customer criado com sucesso",
    "detailMessage": "O cliente foi cadastrado e está ativo no sistema"
  }]
}
```

**Para erros:**
```json
{
  "code": "VALIDATION_FAILED",
  "type": "error",
  "message": "Dados de validação inválidos",
  "detailMessage": "Um ou mais campos contêm valores inválidos",
  "details": [
    {
      "code": "VALIDATION_ERROR",
      "message": "O CGC informado não é válido",
      "detailMessage": "Erro de validação no campo cgc: O CGC informado não é válido"
    }
  ]
}
```

### Autenticação API (Laravel Sanctum):
```php
// ✅ Configuração de autenticação
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserController::class, 'profile']);
});

// ✅ Login e geração de token
public function login(Request $request)
{
    // validação e autenticação
    $token = $user->createToken('API Token')->plainTextToken;
    
    return response()->json([
        'success' => true,
        'data' => [
            'user' => $user,
            'token' => $token
        ]
    ]);
}
```

## 🔐 Sistema de Permissões (Spatie Permission)

### Estrutura de Permissões por Entidade:

#### Customers:
- `customers.view` - Visualizar clientes
- `customers.create` - Criar clientes
- `customers.update` - Atualizar clientes
- `customers.delete` - Deletar clientes

#### Projects:
- `projects.view` - Visualizar projetos
- `projects.view_sensitive_data` - Ver dados sensíveis
- `projects.create` - Criar projetos
- `projects.update` - Atualizar projetos
- `projects.delete` - Deletar projetos
- `projects.assign_people` - Atribuir pessoas
- `projects.change_status` - Alterar status

#### Hours:
- `hours.view` - Visualizar apontamentos
- `hours.view_own` - Ver próprios apontamentos
- `hours.view_all` - Ver todos os apontamentos
- `hours.create` - Criar apontamentos
- `hours.update_own` - Atualizar próprios
- `hours.update_all` - Atualizar todos
- `hours.delete_own` - Deletar próprios
- `hours.delete_all` - Deletar todos
- `hours.approve` - Aprovar apontamentos
- `hours.reject` - Rejeitar apontamentos

### Middleware de Permissões:
```php
// Proteger rotas com permissões (admins sempre têm acesso)
Route::middleware('permission.or.admin:customers.view')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
});

Route::middleware('permission.or.admin:customers.create')->group(function () {
    Route::post('/customers', [CustomerController::class, 'store']);
});
```

### CORS Configuration:
```php
// ✅ Configurar CORS adequadamente no config/cors.php
'paths' => ['api/*'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost:3000'], // Seu frontend
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

## 🔐 Segurança em Primeiro Lugar

### Validação e Sanitização:
- **SEMPRE** valide todas as entradas do usuário
- Use Form Request Validation do Laravel
- Sanitize dados antes de salvar no banco
- Use `htmlspecialchars()` ou `strip_tags()` quando necessário

```php
// ✅ Exemplo correto
public function store(CreateCustomerRequest $request)
{
    $validated = $request->validated(); // Já validado
    
    // Remove caracteres especiais do CGC
    $validated['cgc'] = preg_replace('/[^0-9]/', '', $validated['cgc']);
    
    $customer = Customer::create($validated);
    
    // Valida CGC após criação
    if (!$customer->isValidCgc()) {
        $customer->delete();
        return response()->json([
            'success' => false,
            'message' => 'CGC inválido'
        ], 422);
    }
    
    return response()->json([
        'success' => true,
        'data' => $customer
    ], 201);
}
```

### Proteção contra SQL Injection:
- **SEMPRE** use Eloquent ORM ou Query Builder
- **NUNCA** concatene SQL diretamente
- Use prepared statements quando necessário

```php
// ✅ Correto - Eloquent
Customer::where('cgc', $cgc)->first();

// ✅ Correto - Query Builder
DB::table('customers')->where('nome', 'like', "%{$search}%")->get();

// ❌ NUNCA FAÇA ISSO
DB::select("SELECT * FROM customers WHERE cgc = '$cgc'");
```

### Autenticação e Autorização API:
- Use `auth:sanctum` para autenticação de API
- Implemente políticas (Policies) para autorização
- Use `bcrypt` ou `Hash::make()` para senhas
- Configure rate limiting específico para APIs

```php
// ✅ Middleware de autenticação API
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
});

// ✅ Rate limiting para API
Route::middleware('throttle:api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
});

// ✅ Hash de senhas
$user->password = Hash::make($request->password);
```

## 📊 Exemplos Práticos de Controllers

### CustomerController Exemplo (Seguindo Padrões):
```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Customers",
 *     description="Gerenciamento de Clientes"
 * )
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->get('pageSize', 15), 100);
        $search = $request->get('search');
        
        $query = Customer::query();
        
        // Filtros PO-UI
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nome', 'like', "%{$search}%")
                  ->orWhere('cgc', 'like', "%{$search}%");
            });
        }
        
        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                if (str_starts_with($field, '-')) {
                    $query->orderBy(substr($field, 1), 'desc');
                } else {
                    $query->orderBy($field, 'asc');
                }
            }
        } else {
            $query->orderBy('nome');
        }
        
        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $customers = $query->paginate($perPage, ['*'], 'page', $page);
        
        // Resposta PO-UI
        return response()->json([
            'hasNext' => $customers->hasMorePages(),
            'items' => $customers->items()
        ]);
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['cgc'] = preg_replace('/[^0-9]/', '', $validated['cgc']);
        
        $customer = Customer::create($validated);
        
        if (!$customer->isValidCgc()) {
            $customer->delete();
            return response()->json([
                'code' => 'INVALID_CGC',
                'type' => 'error',
                'message' => 'CGC inválido',
                'detailMessage' => 'O CGC informado não é válido'
            ], 422);
        }
        
        return response()->json($customer, 201);
    }
}
```

### Form Request Exemplo:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class CreateCustomerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'nome' => 'required|string|max:255|min:2',
            'cgc' => 'required|string|unique:customers,cgc'
        ];
    }

    public function messages()
    {
        return [
            'nome.required' => 'O nome é obrigatório',
            'cgc.required' => 'O CGC é obrigatório',
            'cgc.unique' => 'Este CGC já está sendo usado',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $message,
                    'detailMessage' => "Erro de validação no campo {$field}: {$message}"
                ];
            }
        }

        // Resposta no formato PO-UI para erros
        throw new HttpResponseException(response()->json([
            'code' => 'VALIDATION_FAILED',
            'type' => 'error',
            'message' => 'Dados de validação inválidos',
            'detailMessage' => 'Um ou mais campos contêm valores inválidos',
            'details' => $errors
        ], 422));
    }
}
```

## 🧪 Testes com cURL (Exemplos)

### Login:
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "admin@timeconect.com",
    "password": "admin123456"
  }'
```

### Listar Customers com Paginação:
```bash
# Primeira página, 10 registros
curl -X GET "http://localhost:8000/api/customers?page=1&pageSize=10" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"

# Com filtros e ordenação
curl -X GET "http://localhost:8000/api/customers?page=1&pageSize=20&search=Silva&order=nome,-created_at" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Criar Customer:
```bash
curl -X POST http://localhost:8000/api/customers \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{
    "nome": "João Silva",
    "cgc": "12345678901"
  }'
```

## 🚀 Comandos de Desenvolvimento

### Configuração API:
```bash
# Instalar Laravel Sanctum
docker-compose exec app composer require laravel/sanctum
docker-compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
docker-compose exec app php artisan migrate

# Instalar Spatie Permission
docker-compose exec app composer require spatie/laravel-permission
docker-compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
docker-compose exec app php artisan migrate
```

### Iniciar Projeto:
```bash
./setup.sh  # Configuração automática
```

### Desenvolvimento Diário:
```bash
# Subir containers
docker-compose up -d

# Ver logs
docker-compose logs -f app

# Entrar no container
docker-compose exec app bash

# Parar containers
docker-compose down
```

### Limpeza e Cache:
```bash
# Limpar caches
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan view:clear
docker-compose exec app php artisan route:clear
```

### 📋 Checklist Automático para Código API

Ao gerar código, verificar:
- [ ] Rotas em `routes/api.php`
- [ ] Middleware `api` aplicado
- [ ] **Respostas JSON no padrão PO-UI** (`hasNext`+`items` para coleções)
- [ ] **Paginação PO-UI** (`page` e `pageSize`)
- [ ] **Ordenação PO-UI** (parâmetro `order` com `-` para DESC)
- [ ] **Filtros PO-UI** (`property=value`)
- [ ] **Erros PO-UI** (`code`, `message`, `detailMessage`)
- [ ] Autenticação `auth:sanctum` quando necessário
- [ ] Middleware de permissões `permission.or.admin:` aplicado
- [ ] Validação de entrada completa com Form Requests
- [ ] Status codes HTTP apropriados
- [ ] Documentação Swagger da rota incluída
- [ ] Validação de CGC para customers (quando aplicável)
- [ ] Soft deletes implementado (quando aplicável)

### 🎯 Padrões PO-UI Obrigatórios

#### Estruturas Obrigatórias:
```php
// Coleções: SEMPRE usar hasNext + items
['hasNext' => bool, 'items' => array]

// Erros: SEMPRE usar code + message + detailMessage  
['code' => string, 'message' => string, 'detailMessage' => string]

// Paginação: SEMPRE usar page + pageSize
?page=1&pageSize=20

// Ordenação: SEMPRE usar order com -
?order=nome,-created_at

// Filtros: SEMPRE usar property=value ou search
?nome=joão&search=silva
```

### Status Codes Padronizados:
- `200` - OK (GET, PUT bem-sucedidos)
- `201` - Created (POST bem-sucedido)
- `204` - No Content (DELETE bem-sucedido)
- `400` - Bad Request (dados inválidos)
- `401` - Unauthorized (sem autenticação)
- `403` - Forbidden (sem permissão)
- `404` - Not Found (recurso não encontrado)
- `422` - Unprocessable Entity (validação falhou)
- `500` - Internal Server Error (erro do servidor)

---

## ⚠️ IMPORTANTE - PROJETO TIME CONECT API

1. **SEMPRE** execute comandos PHP dentro dos containers
2. **SEMPRE** retorne respostas JSON padronizadas PO-UI
3. **SEMPRE** use `auth:sanctum` para autenticação
4. **SEMPRE** use middleware de permissões `permission.or.admin:`
5. **SEMPRE** valide Content-Type application/json
6. **SEMPRE** documente todas as rotas da API com Swagger
7. **SEMPRE** use Eloquent/Query Builder para banco
8. **SEMPRE** implemente validação de CGC para customers
9. **SEMPRE** use soft deletes quando apropriado
10. **SEMPRE** siga os padrões PO-UI para paginação, filtros e ordenação
11. **NUNCA** use views/Blade neste projeto
12. **NUNCA** use sessões web (stateless)
13. **NUNCA** commite `.env` no repositório
14. **NUNCA** concatene SQL diretamente

**Este é um projeto API REST para apontamento de horas - mantenha-o stateless, seguro e seguindo os padrões PO-UI!** 🛡️🔌⏰ 