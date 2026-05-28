<?php

namespace App\Services;

use App\Models\User;

/**
 * Permissões definidas em código — nunca dependem de seed ou banco.
 * Espelha exatamente o RoleSeeder, mas como arrays PHP estáticos.
 */
class PermissionService
{
    public static function for(User $user): array
    {
        $base = match ($user->type) {
            'admin'          => self::adminPermissions(),
            'administrativo' => self::administrativoPermissions(),
            'coordenador'    => self::coordenadorPermissions(),
            'consultor'      => self::consultorPermissions(),
            'cliente'        => self::clientePermissions(),
            'parceiro_admin' => self::parceiroAdminPermissions(),
            default          => [],
        };

        // Coordenador de Projetos: acesso ao Cadastro de Usuários restrito a
        // RESETAR SENHA (precisa de view_all pra enxergar a lista e do
        // reset_password pro endpoint). Demais ações ficam gateadas na tela /users.
        if ($user->type === 'coordenador' && $user->coordinator_type === 'projetos') {
            $base = array_merge($base, ['users.view_all', 'users.reset_password']);
        }

        // Admin já tem tudo — extras são irrelevantes
        if (in_array('*', $base, true)) {
            return $base;
        }

        $extra = $user->extra_permissions ?? [];

        // Permissões dos grupos vinculados ao usuário
        $groupPermissions = [];
        if ($user->relationLoaded('permissionGroups')) {
            foreach ($user->permissionGroups as $group) {
                $groupPermissions = array_merge($groupPermissions, $group->permissions ?? []);
            }
        } else {
            $groupPermissions = $user->permissionGroups()
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->all();
        }

        return array_values(array_unique(array_merge($base, $extra, $groupPermissions)));
    }

    /**
     * Retorna todas as permissões disponíveis para exibição na UI.
     * Não inclui '*' (admin) — é usado apenas para selecionar extras.
     */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_merge(
            self::administrativoPermissions(),
            self::coordenadorPermissions(),
            self::consultorPermissions(),
            self::clientePermissions(),
            self::parceiroAdminPermissions(),
        )));
    }

    // ── Administrator ────────────────────────────────────────────────────────
    private static function adminPermissions(): array
    {
        return ['*'];
    }

    // ── Administrativo ───────────────────────────────────────────────────────
    private static function administrativoPermissions(): array
    {
        return [
            'dashboard.view',
            // Contratos / Kanban
            'contracts.view', 'contracts.manage',
            'projects.view', 'projects.assign_consultants', 'projects.change_status',
            // Fechamento completo
            'fechamento.view', 'fechamento.manage', 'fechamento.fechar', 'fechamento.reabrir',
            // Apontamentos
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'hours.view_all', 'hours.update_all', 'hours.delete_all',
            // Despesas — incluindo pagamento e estorno
            'expenses.view', 'expenses.manage', 'expenses.approve', 'expenses.reject', 'expenses.view_all', 'expenses.pay',
            // Clientes — acesso total
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'customers.manage',
            // Usuários — acesso total
            'users.view', 'users.view_all', 'users.create', 'users.update', 'users.delete',
            'users.reset_password', 'users.view_own_profile', 'users.update_own_profile',
            // Aprovações
            'approvals.view', 'approvals.manage',
            // Relatórios
            'reports.view', 'reports.export',
            // Configurações
            'settings.view',
        ];
    }

    // ── Coordenador ──────────────────────────────────────────────────────────
    private static function coordenadorPermissions(): array
    {
        return [
            'dashboard.view', 'dashboard.manager',
            'dashboards.view', 'dashboards.bank_hours_fixed.view', 'dashboards.bank_hours_monthly.view',
            'customers.view',
            'projects.view', 'projects.assign_consultants', 'projects.change_status',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve', 'timesheets.view_project_full',
            'hours.view_all', 'hours.update_all', 'hours.delete_all',
            'expenses.view', 'expenses.manage', 'expenses.approve', 'expenses.view_all',
            'users.view', 'users.view_own_profile', 'users.update_own_profile', 'users.view_team',
            'financial.view_own_rate', 'financial.view_project_cost',
            'reports.view', 'reports.export',
            'consultant_groups.view',
        ];
    }

    // ── Consultor ────────────────────────────────────────────────────────────
    private static function consultorPermissions(): array
    {
        return [
            'dashboard.view', 'dashboard.consultant',
            'customers.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage',
            'expenses.view', 'expenses.manage',
            'users.view_own_profile', 'users.update_own_profile',
            'financial.view_own_rate', 'financial.view_partner_rate',
        ];
    }

    // ── Cliente ──────────────────────────────────────────────────────────────
    private static function clientePermissions(): array
    {
        return [
            'dashboard.view',
            'dashboards.view',
            'dashboards.bank_hours_fixed.view',
            'dashboards.bank_hours_monthly.view',
            'dashboards.on_demand.view',
            'projects.view',
            'timesheets.view', 'timesheets.view_project_summary',
            'expenses.view',
            'users.view_own_profile', 'users.update_own_profile',
            'reports.view',
        ];
    }

    // ── Parceiro ADM ─────────────────────────────────────────────────────────
    private static function parceiroAdminPermissions(): array
    {
        return [
            'dashboard.view', 'dashboards.view',
            'projects.view',
            'timesheets.view', 'timesheets.manage', 'timesheets.approve',
            'timesheets.view_project_full',
            'expenses.view', 'expenses.manage',
            'customers.view',
            'users.view_own_profile', 'users.update_own_profile', 'users.view_team',
            'users.create', 'users.update', 'users.reset_password',
            'financial.view_partner_rate',
            'partners.view',
            'reports.view',
        ];
    }
}
