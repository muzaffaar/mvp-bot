<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Staff;

class StaffPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->can(Permission::StaffView->value);
    }

    public function view(Staff $staff, Staff $target): bool
    {
        return $staff->can(Permission::StaffView->value);
    }

    public function create(Staff $staff): bool
    {
        return $staff->can(Permission::StaffCreate->value);
    }

    public function update(Staff $staff, Staff $target): bool
    {
        return $staff->can(Permission::StaffUpdate->value);
    }

    public function delete(Staff $staff, Staff $target): bool
    {
        return $staff->can(Permission::StaffDelete->value);
    }

    public function merge(Staff $staff): bool
    {
        return $staff->can(Permission::StaffMerge->value);
    }
}
