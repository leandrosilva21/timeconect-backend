# 📖 Guia Completo do Swagger - Time Conect API

## 🚀 Acessos Rápidos

- **Interface Swagger UI:** http://localhost:8000/api/documentation
- **JSON da API:** http://localhost:8000/docs
- **Health Check:** http://localhost:8000/api/v1/health

## 🛠️ Comandos Essenciais

```bash
# Gerar documentação
docker-compose exec app php artisan l5-swagger:generate

# Limpar cache e regenerar
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan l5-swagger:generate

# Publicar configurações (caso precise personalizar)
docker-compose exec app php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

## 📝 Como Documentar Endpoints

### 1. Estrutura Básica de Anotação

```php
/**
 * @OA\[METHOD](
 *     path="/api/v1/endpoint",
 *     tags={"Tag Name"},
 *     summary="Título curto",
 *     description="Descrição detalhada do endpoint",
 *     security={{"bearerAuth":{}}}, // Para endpoints protegidos
 *     @OA\RequestBody(...),
 *     @OA\Response(...)
 * )
 */
```

### 2. RequestBody para POST/PUT

```php
@OA\RequestBody(
    required=true,
    @OA\JsonContent(
        required={"campo1","campo2"},
        @OA\Property(property="campo1", type="string", example="valor"),
        @OA\Property(property="campo2", type="integer", example=123),
        @OA\Property(property="opcional", type="string", example="opcional")
    )
)
```

### 3. Responses Detalhadas

```php
@OA\Response(
    response=200,
    description="Sucesso",
    @OA\JsonContent(
        @OA\Property(property="message", type="string", example="Operação realizada"),
        @OA\Property(
            property="data",
            type="object",
            @OA\Property(property="id", type="integer", example=1),
            @OA\Property(property="name", type="string", example="Nome")
        )
    )
),
@OA\Response(response=401, description="Não autorizado"),
@OA\Response(response=422, description="Dados inválidos")
```

## 🎯 Tags do Projeto

| Tag | Uso |
|-----|-----|
| `Autenticação` | Login, logout, verificação de tokens |
| `Usuário` | CRUD de dados do usuário |
| `Recuperação de Senha` | Reset e recuperação de senhas |
| `Sistema` | Health check, status, utilitários |

## 🔐 Segurança na Documentação

### Para Endpoints Protegidos:
```php
/**
 * @OA\Get(
 *     security={{"bearerAuth":{}}},
 *     // ... resto da documentação
 * )
 */
```

### Headers Automáticos:
O Swagger já está configurado para:
- `Authorization: Bearer {token}`
- `Content-Type: application/json`
- `Accept: application/json`

## 🧪 Testando na Interface

### 1. Endpoints Públicos:
- Clique em "Try it out"
- Preencha os dados
- Execute

### 2. Endpoints Protegidos:
1. Faça login em `/api/v1/auth/login`
2. Copie o token retornado
3. Clique em "Authorize" no topo da página
4. Cole o token no formato: `Bearer seu_token_aqui`
5. Agora pode testar endpoints protegidos

## 📊 Exemplos Práticos

### Login (Público):
```json
POST /api/v1/auth/login
{
  "email": "admin@timeconect.com",
  "password": "admin123456"
}
```

### Dados do Usuário (Protegido):
```
GET /api/v1/user
Authorization: Bearer 1|token_aqui...
```

### Atualizar Perfil (Protegido):
```json
PUT /api/v1/user/profile
Authorization: Bearer 1|token_aqui...
{
  "name": "Novo Nome",
  "email": "novo@email.com"
}
```

## 🔄 Workflow de Desenvolvimento

### Ao Criar Novo Endpoint:

1. **Implementar a lógica** no controller
2. **Adicionar anotações Swagger** completas
3. **Regenerar documentação:**
   ```bash
   docker-compose exec app php artisan l5-swagger:generate
   ```
4. **Testar na interface** Swagger
5. **Validar** todos os cenários (sucesso/erro)

### Checklist de Documentação:
- [ ] Path correto
- [ ] Method correto (GET, POST, PUT, DELETE)
- [ ] Tag apropriada
- [ ] Summary e description claros
- [ ] RequestBody (se aplicável)
- [ ] Todos os responses possíveis
- [ ] Exemplos realistas
- [ ] Security (se protegido)

## 🚨 Problemas Comuns

### Documentação não aparece:
```bash
# Limpar caches
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan l5-swagger:generate
```

### Erro de sintaxe nas anotações:
- Verificar aspas duplas
- Verificar vírgulas
- Verificar fechamento de parênteses

### Token não autoriza:
- Verificar formato: `Bearer token_completo`
- Token pode ter expirado
- Fazer novo login

## 📈 Benefícios

### Para Desenvolvedores:
- ✅ Documentação sempre atualizada
- ✅ Testes direto na interface
- ✅ Exemplos funcionais
- ✅ Menos tempo debugando

### Para Integração:
- ✅ Documentação completa da API
- ✅ Exemplos de request/response
- ✅ Códigos de erro documentados
- ✅ Interface amigável para testes

## 🎨 Personalização

### Configurações principais:
- Arquivo: `config/l5-swagger.php`
- Título: Já configurado como "Time Conect API Documentation"
- Path: `/api/documentation`

### Para alterar aparência:
```bash
# Publicar views (opcional)
docker-compose exec app php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider" --tag="views"
```

---

## 💡 Dicas Finais

1. **Sempre documente** antes de fazer commit
2. **Use exemplos reais** dos dados
3. **Teste na interface** antes de entregar
4. **Mantenha tags consistentes**
5. **Documente todos os códigos de erro**

**Lembre-se:** A documentação Swagger é obrigatória para todos os endpoints! 🚀 