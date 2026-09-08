<?php

namespace App\Services\Authorization;

use App\Models\Group;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class GroupAuthorizationService
{
    public function hasRole(
        Staff $staff,
        Group $group,
        string $role
    ): bool {
        return $staff->groups()
            ->where('groups.id', $group->id)
            ->wherePivot('status', 'active')
            ->wherePivot('role_id', function ($query) use ($role) {
                $query->select('id')
                    ->from('roles')
                    ->where('name', $role);
            })
            ->exists();
    }

    public function hasPermission(
        Staff $staff,
        Group $group,
        string $permission
    ): bool {
        $membership = $staff->groups()
            ->where('groups.id', $group->id)
            ->wherePivot('status', 'active')
            ->first();

        if (! $membership) {
            return false;
        }

        $role = $membership->pivot->role_id;

        if (! $role) {
            return false;
        }

        return DB::table('role_has_permissions')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_has_permissions.permission_id'
            )
            ->where('role_has_permissions.role_id', $role)
            ->where('permissions.name', $permission)
            ->exists();
    }
}
