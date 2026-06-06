# 🚀 Deploy Rápido - Time Conect Backend

## 📋 Resumo

Este projeto usa **GitLab CI/CD** com **Docker Compose** para deploy automático em produção.

## ⚡ Deploy Automático (Recomendado)

### 1. Configurar GitLab Runner
```bash
# No servidor de produção
gitlab-runner register \
  --url "https://gitlab.com/" \
  --registration-token "YOUR_TOKEN" \
  --executor "docker" \
  --docker-image "docker:20.10.16" \
  --tag-list "production" \
  --description "Production Runner"
```

### 2. Fazer Deploy
```bash
# Push para branch production
git checkout production
git merge main
git push origin production

# Ou via GitLab UI: CI/CD > Pipelines > Run Pipeline
```

## 🛠️ Deploy Manual

### Usando o Script Automatizado
```bash
# No servidor de produção
cd timeconect-backend
./deploy.sh deploy
```

### Comandos Manuais
```bash
# 1. Parar containers
docker-compose down

# 2. Subir com build
docker-compose up -d --build

# 3. Executar migrações
docker-compose exec app php artisan migrate --force

# 4. Limpar caches
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear

# 5. Otimizar para produção
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
```

## ⚙️ Configuração Inicial

### 1. Arquivo .env
```bash
# Copiar exemplo
cp env.production.example .env

# Editar configurações
nano .env
```

### 2. Gerar APP_KEY
```bash
docker-compose exec app php artisan key:generate
```

### 3. Configurar Banco
```bash
# Verificar conexão
docker-compose exec app php artisan tinker
```

## 📊 Monitoramento

### Status dos Containers
```bash
docker-compose ps
```

### Logs
```bash
# Aplicação
docker-compose logs -f app

# Nginx
docker-compose logs -f webserver

# Banco
docker-compose logs -f db
```

### Saúde da API
```bash
curl http://localhost:8000/api/v1/health
```

## 🔄 Rollback

### Via GitLab
1. CI/CD > Pipelines
2. Encontrar pipeline anterior
3. Clicar em "Rollback"

### Via Script
```bash
./deploy.sh rollback
```

### Manual
```bash
docker-compose down
docker-compose up -d
```

## 🛡️ Segurança

### Checklist
- [ ] APP_DEBUG=false
- [ ] Senhas fortes no banco
- [ ] APP_KEY gerada
- [ ] CORS configurado
- [ ] Rate limiting ativo

### Variáveis Críticas
```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:sua_chave_aqui
DB_PASSWORD=senha_forte_aqui
```

## 📁 Estrutura de Arquivos

```
timeconect-backend/
├── .gitlab-ci.yml          # Pipeline CI/CD
├── deploy.sh               # Script de deploy manual
├── docker-compose.yml      # Configuração Docker
├── env.production.example  # Exemplo de .env
└── GITLAB_CI_CD_GUIDE.md   # Guia completo
```

## 🆘 Troubleshooting

### Container não sobe
```bash
docker-compose logs app
netstat -tulpn | grep :8000
```

### Migrações falham
```bash
docker-compose exec app php artisan migrate --force
```

### Cache não limpa
```bash
docker-compose exec app php artisan cache:clear
```

## 📞 Suporte

- **Logs:** `docker-compose logs -f app`
- **Status:** `docker-compose ps`
- **Shell:** `docker-compose exec app bash`
- **Documentação:** `GITLAB_CI_CD_GUIDE.md`

---

**🎯 Pronto para deploy!** Configure o GitLab Runner e faça push para a branch `production`.
