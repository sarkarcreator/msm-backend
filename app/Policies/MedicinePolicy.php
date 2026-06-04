<?php

namespace App\Policies;

use App\Models\User;

class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowedBusiness($user)
            && in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Doctor', 'Compounder', 'Assistant', 'Pharmacy Staff'], true);
    }

    public function mutate(User $user): bool
    {
        return $this->allowedBusiness($user)
            && in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager', 'Doctor', 'Pharmacy Staff'], true);
    }

    public function delete(User $user): bool
    {
        return $this->allowedBusiness($user)
            && in_array(optional($user->role)->name, ['Super Admin', 'Admin', 'Manager'], true);
    }

    private function allowedBusiness(User $user): bool
    {
        if (optional($user->role)->name === 'Super Admin') {
            return true;
        }

        return in_array($user->business_type, ['Hospital', 'Pharmacy'], true);
    }
}
