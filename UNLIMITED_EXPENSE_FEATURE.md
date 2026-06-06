# Feature: Despesas Ilimitadas para Projetos com Cliente Responsável

## 📋 Descrição

Esta feature adiciona a capacidade de marcar projetos com despesas ilimitadas quando o **Cliente** é o responsável pelo pagamento das despesas. Isso permite que consultores façam apontamentos de despesas sem limite de valor em casos como viagens, hospedagens, alimentação, transporte, etc., que são cobertos pelo cliente.

## 🎯 Objetivo

Permitir que projetos onde o cliente é responsável pelas despesas possam ter um checkbox "Valor Indefinido" que, quando marcado, remove a validação de valor máximo por consultor (`max_expense_per_consultant`) ao criar ou editar despesas.

## 🔧 Mudanças Implementadas

### Backend (Laravel)

#### 1. Migration
**Arquivo:** `database/migrations/2025_01_17_120000_add_unlimited_expense_to_projects_table.php`

- Adiciona coluna `unlimited_expense` (boolean, default: false) na tabela `projects`
- Coluna posicionada após `max_expense_per_consultant`

```php
Schema::table('projects', function (Blueprint $table) {
    $table->boolean('unlimited_expense')->default(false)->after('max_expense_per_consultant');
});
```

#### 2. Model Project
**Arquivo:** `app/Models/Project.php`

- Adicionado `unlimited_expense` ao array `$fillable`
- Adicionado cast para boolean no array `$casts`

```php
protected $fillable = [
    // ... outros campos
    'max_expense_per_consultant',
    'unlimited_expense',
    'expense_responsible_party',
    // ... outros campos
];

protected $casts = [
    // ... outros campos
    'unlimited_expense' => 'boolean',
    // ... outros campos
];
```

#### 3. ProjectController
**Arquivo:** `app/Http/Controllers/ProjectController.php`

- Adicionada validação para `unlimited_expense` nos métodos `store()` e `update()`

```php
'unlimited_expense' => 'nullable|boolean',
```

#### 4. ExpenseController
**Arquivo:** `app/Http/Controllers/ExpenseController.php`

- Modificada validação de valor máximo por consultor para respeitar o flag `unlimited_expense`
- Aplicado nos métodos `store()` e `update()`

**Antes:**
```php
if ($project->max_expense_per_consultant && $request->amount > $project->max_expense_per_consultant) {
    // retornar erro
}
```

**Depois:**
```php
if (!$project->unlimited_expense && $project->max_expense_per_consultant && $request->amount > $project->max_expense_per_consultant) {
    // retornar erro
}
```

### Frontend (Angular)

#### 1. Interface TypeScript
**Arquivo:** `src/app/models/project.interface.ts`

- Adicionado campo `unlimited_expense?: boolean` em:
  - `IProject`
  - `IProjectCreateRequest`
  - `IProjectUpdateRequest`

#### 2. Formulário de Projeto
**Arquivo:** `src/app/features/projects/project-form/project-form.component.ts`

**Mudanças realizadas:**

1. **Adicionado campo no FormGroup:**
   ```typescript
   unlimited_expense: [false]
   ```

2. **Importado PoCheckboxModule** nos imports do componente

3. **Adicionados métodos de gerenciamento:**
   - `shouldShowUnlimitedExpenseCheckbox()`: Verifica se deve mostrar o checkbox (apenas quando `expense_responsible_party === 'client'`)
   - `onUnlimitedExpenseChange()`: Gerencia o comportamento quando o checkbox é marcado/desmarcado
   - `onExpenseResponsiblePartyChange()`: Gerencia mudanças no responsável pelas despesas

4. **Adicionado checkbox no template HTML:**
   ```html
   <!-- Checkbox de Despesa Ilimitada (apenas quando Cliente é responsável) -->
   <div class="po-row" *ngIf="shouldShowUnlimitedExpenseCheckbox()">
     <div class="po-md-12">
       <po-checkbox
         name="unlimited_expense"
         formControlName="unlimited_expense"
         p-label="Valor Indefinido"
         p-help="Quando marcado, não há limite de valor para despesas neste projeto">
       </po-checkbox>
     </div>
   </div>
   ```

5. **Subscrições para mudanças:**
   - Monitora mudanças em `unlimited_expense`
   - Monitora mudanças em `expense_responsible_party`

6. **Lógica de comportamento:**
   - Quando `unlimited_expense` é marcado:
     - Campo `max_expense_per_consultant` é limpo e desabilitado
   - Quando desmarcado:
     - Campo `max_expense_per_consultant` é reabilitado
   - Quando `expense_responsible_party` não é 'client':
     - Checkbox `unlimited_expense` é desmarcado
     - Campo `max_expense_per_consultant` é reabilitado

## 🚀 Como Usar

### Para criar/editar um projeto com despesas ilimitadas:

1. Acesse o formulário de projeto
2. Preencha os campos obrigatórios
3. Na seção "Política de Despesas":
   - Selecione **"Cliente"** como "Responsável pelas Despesas"
   - O checkbox **"Valor Indefinido"** aparecerá
   - Marque o checkbox se desejar permitir despesas ilimitadas
   - Quando marcado, o campo "Valor Máximo por Consultor" será desabilitado

### Comportamento na criação de despesas:

- Se `unlimited_expense = true`: Não há validação de valor máximo
- Se `unlimited_expense = false`: Validação normal de `max_expense_per_consultant` é aplicada

## ✅ Validações

### Backend
- `unlimited_expense` é opcional (nullable)
- Tipo: boolean
- Validação de despesas considera o flag antes de verificar o limite

### Frontend
- Checkbox só é visível quando `expense_responsible_party === 'client'`
- Campo `max_expense_per_consultant` é desabilitado quando checkbox está marcado
- Checkbox é automaticamente desmarcado quando responsável não é cliente

## 📊 Estrutura de Dados

### Tabela projects
```sql
unlimited_expense TINYINT(1) DEFAULT 0 AFTER max_expense_per_consultant
```

### Request/Response JSON
```json
{
  "name": "Projeto Exemplo",
  "code": "PROJ001",
  "expense_responsible_party": "client",
  "max_expense_per_consultant": 1000.00,
  "unlimited_expense": true,
  // ... outros campos
}
```

## 🔐 Segurança

- Validação tanto no frontend quanto no backend
- Campo protegido por validação do tipo de responsável
- Não afeta projetos existentes (default: false)
- Administradores podem modificar o valor a qualquer momento

## 📝 Observações

1. O checkbox só é relevante quando o **Cliente** é o responsável pelas despesas
2. Quando ativado, consultores podem lançar despesas de qualquer valor
3. O campo `max_expense_per_consultant` ainda pode ter um valor definido, mas não será validado quando `unlimited_expense = true`
4. A feature foi implementada de forma retrocompatível (não quebra projetos existentes)

## 🧪 Testes

Para testar a funcionalidade:

1. **Criar projeto com despesa ilimitada:**
   - Criar novo projeto
   - Selecionar "Cliente" como responsável
   - Marcar "Valor Indefinido"
   - Salvar
   - Tentar criar despesa com valor alto

2. **Editar projeto existente:**
   - Abrir projeto existente
   - Alterar responsável para "Cliente"
   - Marcar "Valor Indefinido"
   - Salvar
   - Verificar que validação de despesas não é aplicada

3. **Desabilitar despesa ilimitada:**
   - Abrir projeto com despesa ilimitada
   - Desmarcar "Valor Indefinido"
   - Definir valor máximo
   - Salvar
   - Verificar que validação voltou a funcionar

## 🔄 Migration Status

✅ Migration executada com sucesso em: 2025-01-17

```bash
INFO  Running migrations.  
2025_01_17_120000_add_unlimited_expense_to_projects_table ...... 4.68ms DONE
```

---

**Data de Implementação:** 17 de Janeiro de 2025  
**Versão:** 1.0.0  
**Autor:** Sistema Time Conect

