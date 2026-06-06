# 🔧 Guia: Recriar Banco de Dados SQLite

## 📋 Situação
Você formatou a máquina e perdeu o arquivo `database.sqlite` usado para desenvolvimento local.

## ✅ Solução Rápida (Recomendada)

Execute o script automático:

```bash
./recreate-sqlite-db.sh
```

Este script irá:
1. ✅ Criar/recriar o arquivo `database/database.sqlite`
2. ✅ Criar/configurar o arquivo `.env` para usar SQLite
3. ✅ Gerar a `APP_KEY` se necessário
4. ✅ Executar todas as migrations
5. ✅ Executar todos os seeders (incluindo criação do usuário admin)

## 📝 Solução Manual (Passo a Passo)

Se preferir fazer manualmente ou se o script não funcionar:

### 1. Criar o arquivo database.sqlite

```bash
touch database/database.sqlite
```

### 2. Criar/Configurar o arquivo .env

Se não tiver um arquivo `.env`, crie um com o seguinte conteúdo mínimo:

```env
APP_NAME=Time Conect
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120

SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:3000,::1
API_PREFIX=api/v1
```

**Importante:** Se já tiver um `.env`, certifique-se de que tenha:
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=database/database.sqlite`

### 3. Gerar a APP_KEY

```bash
docker-compose exec app php artisan key:generate
```

### 4. Executar Migrations

```bash
docker-compose exec app php artisan migrate:fresh
```

O comando `migrate:fresh` irá:
- Dropar todas as tabelas existentes
- Recriar todas as tabelas do zero

### 5. Executar Seeders

```bash
docker-compose exec app php artisan db:seed
```

Este comando irá criar:
- ✅ Permissões do sistema
- ✅ Roles (Administrator, Project Manager, Consultant)
- ✅ Usuários padrão (admin, teste, demo)
- ✅ Categorias de despesas
- ✅ Dados de exemplo para timesheets

## 🔐 Credenciais de Acesso

Após executar os seeders, você terá acesso aos seguintes usuários:

| Email | Senha | Role |
|-------|-------|------|
| `admin@timeconect.com` | `admin123456` | Administrator |
| `teste@timeconect.com` | `teste123456` | Project Manager |
| `demo@timeconect.com` | `demo123456` | Consultant |

## ⚠️ Troubleshooting

### Erro: "SQLSTATE[HY000] [14] unable to open database file"

**Causa:** O arquivo `database.sqlite` não existe ou não tem permissões de escrita.

**Solução:**
```bash
touch database/database.sqlite
chmod 664 database/database.sqlite
```

### Erro: "No application encryption key has been specified"

**Causa:** A `APP_KEY` não foi gerada.

**Solução:**
```bash
docker-compose exec app php artisan key:generate
```

### Erro: "SQLSTATE[HY000] General error: 1 no such table"

**Causa:** As migrations não foram executadas.

**Solução:**
```bash
docker-compose exec app php artisan migrate:fresh
docker-compose exec app php artisan db:seed
```

### Docker não está rodando

**Solução:**
1. Inicie o Docker Desktop
2. Execute: `docker-compose up -d`
3. Aguarde os containers iniciarem
4. Execute os comandos novamente

## 🔄 Alternativa: Usar MySQL (Docker)

Se preferir usar MySQL ao invés de SQLite:

1. Configure o `.env` para MySQL:
```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_password
```

2. Execute as migrations e seeders:
```bash
docker-compose exec app php artisan migrate:fresh
docker-compose exec app php artisan db:seed
```

## 📚 Referências

- [Laravel Database Configuration](https://laravel.com/docs/database)
- [Laravel Migrations](https://laravel.com/docs/migrations)
- [Laravel Seeders](https://laravel.com/docs/seeding)
