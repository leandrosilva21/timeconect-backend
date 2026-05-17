<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CoreSeeder extends Seeder
{
    /**
     * Seeders essenciais para qualquer ambiente (inclui produção).
     */
    public function run(): void
    {
        // PermissionSeeder e RoleSeeder removidos: o projeto deixou de usar
        // Spatie/laravel-permission — permissões vêm de PermissionService (arrays
        // PHP por perfil) e roles foram substituídos por users.type.
        $this->call([
            UserSeeder::class,
            ExpenseCategorySeeder::class,
            SystemSettingSeeder::class,
            ServiceAndContractTypeSeeder::class,
        ]);
    }
}

