# 📂 Feature: Subprojetos (Projetos Filhos)

## 📋 Resumo

Implementação completa da funcionalidade de subprojetos no sistema Time Conect, permitindo que projetos tenham projetos filhos, com apontamento de horas nos subprojetos e visualização consolidada de custos.

## 🎯 Funcionalidades Implementadas

### 1. **Estrutura de Hierarquia de Projetos**
- ✅ Projetos podem ter um projeto pai (parent_project_id)
- ✅ Apenas um nível de hierarquia (projetos principais e seus subprojetos)
- ✅ Validações para evitar loops e múltiplos níveis

### 2. **Apontamento de Horas**
- ✅ Consultores apontam horas diretamente no subprojeto
- ✅ Timesheet funciona normalmente para subprojetos
- ✅ Validações de projeto aplicam-se aos subprojetos

### 3. **Visualização de Custos Consolidados**
- ✅ Modal "Ver Custos" mostra:
  - Horas do projeto principal
  - Horas dos subprojetos (separadamente)
  - Total consolidado (principal + subprojetos)
  - Quebra por consultor indicando qual projeto cada hora foi apontada
  - Tabela com resumo de cada subprojeto

## 🔧 Alterações Técnicas

### Backend (Laravel)

#### 1. **Migration**
```
2025_11_25_000001_add_parent_project_id_to_projects_table.php
```
- Adiciona campo `parent_project_id` à tabela `projects`
- Foreign key com cascade delete
- Índice para melhor performance

#### 2. **Model: `app/Models/Project.php`**
- ✅ Campo `parent_project_id` adicionado ao `$fillable`
- ✅ Relacionamento `parentProject()` (BelongsTo)
- ✅ Relacionamento `childProjects()` (HasMany)
- ✅ Método `isSubProject()` - verifica se é subprojeto
- ✅ Método `hasChildProjects()` - verifica se tem filhos

#### 3. **Controller: `app/Http/Controllers/ProjectController.php`**

**Método `index()`:**
- Carrega relacionamento `parentProject` para listar projetos

**Método `store()`:**
- Validação de `parent_project_id`
- Valida que projeto pai não pode ser um subprojeto (evita múltiplos níveis)

**Método `update()`:**
- Validação de `parent_project_id`
- Valida que projeto não pode ser pai de si mesmo
- Valida que projeto pai não pode ser subprojeto
- Valida que projeto com filhos não pode se tornar subprojeto

**Método `show()`:**
- Carrega relacionamentos `parentProject` e `childProjects`

**Método `costSummary()`:**
- **PRINCIPAL MUDANÇA**: Calcula custos consolidados
- Retorna horas do projeto pai separadamente
- Retorna horas dos subprojetos separadamente
- Retorna total consolidado
- Quebra por consultor indica de qual projeto são as horas
- Array `child_projects_summary` com resumo de cada subprojeto

### Frontend (Angular 19)

#### 1. **Interfaces: `src/app/models/project.interface.ts`**
- ✅ `parent_project_id?: number | null`
- ✅ `parentProject?: IProject | null`
- ✅ `childProjects?: IProject[]`

#### 2. **Interfaces: `src/app/models/project-cost.interface.ts`**
- ✅ `IProjectInfo.has_child_projects?: boolean`
- ✅ `IHoursSummary.parent_project_hours?: number`
- ✅ `IHoursSummary.child_projects_hours?: number`
- ✅ `IConsultantBreakdown.projects_breakdown?: IProjectBreakdown[]`
- ✅ Nova interface `IProjectBreakdown` (project_name, project_code, hours)
- ✅ Nova interface `IChildProjectSummary` (id, name, code, total_hours, approved_hours, pending_hours)
- ✅ `IProjectCostSummary.child_projects_summary?: IChildProjectSummary[]`

#### 3. **Formulário: `project-form.component.ts`**
- ✅ Campo de seleção de projeto pai no formulário
- ✅ Carrega apenas projetos principais (sem parent_project_id)
- ✅ Exclui o próprio projeto da lista (ao editar)
- ✅ Campo `parent_project_id` adicionado ao FormGroup
- ✅ Valor enviado na criação/atualização do projeto

#### 4. **Listagem: `project-list.component.ts`**
- ✅ Coluna "Nome" mostra hierarquia visual
- ✅ Subprojetos exibidos como: `↳ Nome do Subprojeto (Sub de: Nome do Pai)`
- ✅ Método `getProjectNameWithHierarchy()` para formatação

#### 5. **Modal de Custos: `project-cost-modal.component.ts`**
- ✅ Seção "Detalhe de Horas por Projeto" (quando há subprojetos)
- ✅ Tabela "Subprojetos" com resumo de cada subprojeto
- ✅ Quebra por consultor mostra distribuição por projeto
- ✅ Indicação visual de "(Principal)" e "(Subprojeto)"
- ✅ Estilos CSS para melhor visualização

## 📊 Estrutura de Dados

### Banco de Dados

```sql
-- Tabela projects
parent_project_id: BIGINT UNSIGNED NULL
FOREIGN KEY (parent_project_id) REFERENCES projects(id) ON DELETE CASCADE
INDEX (parent_project_id)
```

### Resposta da API: `/api/v1/projects/{id}/cost-summary`

```json
{
  "project_info": {
    "id": 1,
    "name": "Projeto Principal",
    "code": "PROJ-001",
    "has_child_projects": true,
    ...
  },
  "hours_summary": {
    "total_logged_hours": 150.5,
    "parent_project_hours": 100.0,
    "child_projects_hours": 50.5,
    ...
  },
  "consultant_breakdown": [
    {
      "consultant_name": "João Silva",
      "total_hours": 80.0,
      "projects_breakdown": [
        {
          "project_name": "Projeto Principal (Principal)",
          "project_code": "PROJ-001",
          "hours": 50.0
        },
        {
          "project_name": "Módulo A (Subprojeto)",
          "project_code": "PROJ-001-A",
          "hours": 30.0
        }
      ],
      ...
    }
  ],
  "child_projects_summary": [
    {
      "id": 2,
      "name": "Módulo A",
      "code": "PROJ-001-A",
      "total_hours": 30.5,
      "approved_hours": 20.0,
      "pending_hours": 10.5
    }
  ]
}
```

## 🔐 Validações Implementadas

### Backend

1. ✅ **Projeto pai deve existir** (exists:projects,id)
2. ✅ **Projeto pai não pode ser um subprojeto** (evita múltiplos níveis)
3. ✅ **Projeto não pode ser pai de si mesmo**
4. ✅ **Projeto com filhos não pode se tornar subprojeto**

### Frontend

1. ✅ **Apenas projetos principais aparecem como opções de projeto pai**
2. ✅ **Ao editar, o próprio projeto não aparece como opção**
3. ✅ **Campo opcional** (pode criar projeto sem projeto pai)

## 🎨 Experiência do Usuário

### 1. **Criação/Edição de Projeto**
- Campo "Projeto Pai (Subprojeto)" aparece após seleção de cliente
- Help text: "Deixe em branco para criar um projeto principal. Selecione um projeto para criar um subprojeto."
- Dropdown mostra: "CÓDIGO - Nome do Projeto"
- Campo com botão de limpar (p-clean)

### 2. **Listagem de Projetos**
- Projetos principais: "Nome do Projeto"
- Subprojetos: "↳ Nome do Subprojeto (Sub de: Nome do Pai)"
- Ícone visual ↳ indica hierarquia

### 3. **Modal de Custos**
- Seção especial quando há subprojetos
- Horas totais = horas do pai + horas dos filhos
- Tabela dedicada aos subprojetos
- Quebra por consultor mostra:
  - Total consolidado por consultor
  - Distribuição por projeto (se trabalhou em mais de um)
  - Labels "(Principal)" e "(Subprojeto)"

## 🧪 Casos de Teste Sugeridos

### Backend
1. ✅ Criar projeto principal (sem parent_project_id)
2. ✅ Criar subprojeto vinculado a projeto principal
3. ✅ Tentar criar subprojeto de um subprojeto (deve falhar)
4. ✅ Tentar tornar projeto com filhos em subprojeto (deve falhar)
5. ✅ Apontar horas no subprojeto
6. ✅ Ver custos do projeto pai (deve incluir horas dos filhos)
7. ✅ Deletar projeto pai (deve deletar filhos em cascata)

### Frontend
1. ✅ Criar projeto sem projeto pai
2. ✅ Criar projeto com projeto pai
3. ✅ Editar projeto e mudar projeto pai
4. ✅ Visualizar lista com hierarquia
5. ✅ Abrir modal de custos de projeto com subprojetos
6. ✅ Verificar quebra por consultor com múltiplos projetos

## 📝 Notas Importantes

1. **Apenas um nível de hierarquia**: Sistema permite apenas projetos principais e seus subprojetos diretos, não há suporte para subprojetos de subprojetos.

2. **Custos sempre consolidados**: Ao visualizar custos de um projeto pai, sempre inclui os custos de todos os subprojetos.

3. **Apontamento direto**: Consultores apontam horas diretamente no subprojeto, não no projeto pai.

4. **Cascade Delete**: Se o projeto pai for deletado, todos os subprojetos são deletados automaticamente.

5. **Compatibilidade**: Projetos existentes continuam funcionando normalmente, pois `parent_project_id` é nullable.

## 🚀 Deploy

### Migration
```bash
cd /path/to/timeconect-backend
docker compose exec app php artisan migrate
```

### Frontend
Nenhuma configuração adicional necessária. As alterações são automáticas após deploy.

---

**Data de Implementação**: 25/11/2025  
**Versão**: 1.0.0  
**Status**: ✅ Completo e Testado

