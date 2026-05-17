# 📋 Seeders e Dados Padrões do Sistema

## 🎯 Visão Geral

O sistema de seeders do Minutor está organizado para separar claramente dados essenciais de produção de dados de desenvolvimento/demo.

## 🏗️ Estrutura de Seeders

### DatabaseSeeder (Principal)

O seeder principal decide automaticamente quais seeders executar baseado no ambiente (`APP_ENV`):

```php
// Sempre executa (qualquer ambiente)
CoreSeeder::class

// Apenas se APP_ENV != 'production'
DevDemoSeeder::class
```

### CoreSeeder - Dados Essenciais ✅

**Execução**: Sempre (produção, homologação, desenvolvimento)

**Seeders incluídos**:
- `UserSeeder` - Usuários iniciais com credenciais padrão
- `ExpenseCategorySeeder` - Taxonomia de categorias de despesas
- `SystemSettingSeeder` - Configurações do sistema
- `ServiceAndContractTypeSeeder` - Tipos de serviço e contrato essenciais

### DevDemoSeeder - Massa de Dados 🧪

**Execução**: Apenas em ambientes de desenvolvimento/homologação (`APP_ENV != 'production'`)

**Seeders incluídos**:
- `DashboardDataSeeder` - Grande volume de dados fictícios para dashboards
- `TimesheetSeeder` - Timesheets de teste com tickets

## 🔒 Dados Protegidos do Sistema

Os dados padrões criados pelos seeders do `CoreSeeder` são **protegidos contra exclusão e alteração do código** através da classe `App\Constants\SystemDefaults`.

### Tipos de Serviço (ServiceType)

**Códigos protegidos**:
- `projeto` - Projetos de desenvolvimento
- `sustentacao` - Serviços de sustentação

**Proteções aplicadas**:
- ❌ Não podem ser excluídos
- ❌ Código (`code`) não pode ser alterado
- ✅ Nome e descrição podem ser atualizados
- ✅ Podem ser ativados/desativados

### Tipos de Contrato (ContractType)

**Códigos protegidos**:
- `closed` - Fechado
- `fixed_hours` - Banco de Horas Fixo
- `monthly_hours` - Banco de Horas Mensal
- `on_demand` - On Demand

**Proteções aplicadas**:
- ❌ Não podem ser excluídos
- ❌ Código (`code`) não pode ser alterado
- ✅ Nome e descrição podem ser atualizados
- ✅ Podem ser ativados/desativados

### Categorias de Despesa (ExpenseCategory)

**Categorias principais protegidas**:
- `transport` - Transporte
- `food` - Alimentação
- `accommodation` - Hospedagem e Viagem
- `representation` - Representação
- `personal` - Pessoais
- `material_equipment` - Material e Equipamento
- `administrative` - Administrativas
- `education` - Educação/Treinamento

**Subcategorias protegidas**: (total de 15 subcategorias)
- Transporte: `taxi_app`, `fuel_parking`, `tickets`, `car_rental`
- Alimentação: `meals_travel`, `snacks`, `client_meal`
- Hospedagem: `hotel_tourism`, `laundry_insurance`
- Representação: `gifts_corporate`, `business_lunch`
- Pessoais: `advance_payment`, `emergency_medicine`
- Material: `office_tech_it`
- Administrativas: `fees_postal_notary`
- Educação: `courses_certs_events`

**Proteções aplicadas**:
- ❌ Não podem ser excluídas
- ❌ Código (`code`) não pode ser alterado
- ✅ Nome e descrição podem ser atualizados
- ✅ Podem ser ativadas/desativadas

## 🚀 Como Usar

### Executar todos os seeders (ambiente atual)

```bash
# Dentro do container Docker
docker-compose exec app php artisan db:seed

# Se APP_ENV=production → só CoreSeeder
# Se APP_ENV=local/staging → CoreSeeder + DevDemoSeeder
```

### Executar apenas dados essenciais

```bash
docker-compose exec app php artisan db:seed --class=CoreSeeder
```

### Executar apenas dados de demo

```bash
docker-compose exec app php artisan db:seed --class=DevDemoSeeder
```

### Executar seeder específico

```bash
docker-compose exec app php artisan db:seed --class=UserSeeder
docker-compose exec app php artisan db:seed --class=ServiceAndContractTypeSeeder
```

## 🔧 Configuração de Ambiente

Para controlar qual conjunto de seeders será executado, configure a variável `APP_ENV` no arquivo `.env`:

```env
# Produção - apenas dados essenciais
APP_ENV=production

# Homologação/Staging - dados essenciais + demo
APP_ENV=staging

# Desenvolvimento - dados essenciais + demo
APP_ENV=local
```

## 📝 Respostas de Erro da API

Quando um usuário tenta excluir ou alterar o código de um registro protegido:

### Exclusão de tipo de serviço protegido

```json
{
  "code": "SYSTEM_TYPE_PROTECTED",
  "type": "error",
  "message": "Não é possível excluir tipo de serviço padrão do sistema",
  "detailMessage": "Este tipo de serviço é essencial para o funcionamento do sistema e não pode ser excluído"
}
```

### Alteração de código de tipo de contrato protegido

```json
{
  "code": "SYSTEM_TYPE_PROTECTED",
  "type": "error",
  "message": "Não é possível alterar o código de um tipo de contrato padrão do sistema",
  "detailMessage": "Este tipo de contrato é essencial para o funcionamento do sistema e seu código não pode ser alterado"
}
```

### Exclusão de categoria de despesa protegida

```json
{
  "code": "SYSTEM_CATEGORY_PROTECTED",
  "type": "error",
  "message": "Não é possível excluir categoria padrão do sistema",
  "detailMessage": "Esta categoria é essencial para o funcionamento do sistema e não pode ser excluída"
}
```

## 🛡️ Implementação Técnica

As proteções são implementadas através de:

1. **Classe de Constantes**: `App\Constants\SystemDefaults`
   - Define arrays com códigos protegidos
   - Métodos auxiliares para verificação

2. **Controllers Protegidos**:
   - `ServiceTypeController` - métodos `update()` e `destroy()`
   - `ContractTypeController` - métodos `update()` e `destroy()`
   - `ExpenseCategoryController` - métodos `update()` e `destroy()`

3. **Validações**:
   - Verificação **antes** de projetos vinculados/despesas
   - Retorno de erro 422 com código identificador
   - Mensagens claras para o usuário

## 📚 Referências

- **CoreSeeder**: `database/seeders/CoreSeeder.php`
- **DevDemoSeeder**: `database/seeders/DevDemoSeeder.php`
- **SystemDefaults**: `app/Constants/SystemDefaults.php`
- **Controllers**: `app/Http/Controllers/`

## ⚠️ Importante

- **Produção**: Nunca execute `DevDemoSeeder` em produção! Use `APP_ENV=production`
- **Códigos protegidos**: Não altere os códigos na classe `SystemDefaults` sem revisar impactos no sistema
- **Idempotência**: Todos os seeders usam `firstOrCreate()` - podem ser executados múltiplas vezes
- **Ordem**: A ordem dos seeders no `CoreSeeder` é importante devido a dependências entre tabelas
