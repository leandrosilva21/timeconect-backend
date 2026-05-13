<?php

namespace App\Policies;

use App\Models\ConsultantGroup;
use App\Models\User;

class ConsultantGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.view');
    }

    public function view(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.view');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.create');
    }

    public function update(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.update');
    }

    public function delete(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.delete');
    }

    public function restore(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->isAdmin()
            || $user->hasAccess('groups.manage')
            || $user->hasAccess('consultant_groups.delete');
    }

    public function forceDelete(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->isAdmin();
    }
}
