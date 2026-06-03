<?php

namespace App\Policies;

use App\Models\User;

class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Doctor', 'Compounder', 'Assistant'], true);
    }

    public function mutate(User $user): bool
    {
        return in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager', 'Doctor'], true);
    }

    public function delete(User $user): bool
    {
        return in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager'], true);
    }
}
