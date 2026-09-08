<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Staff;
use Illuminate\Auth\Access\Response;

class GroupPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Staff $staff): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Staff $staff, Group $group): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Staff $staff): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Staff $staff, Group $group): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Staff $staff, Group $group): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Staff $staff, Group $group): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Staff $staff, Group $group): bool
    {
        return false;
    }
}
