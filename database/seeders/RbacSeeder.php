<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            SpatiePermission::updateOrCreate(
                [
                    'name' => $permission->value,
                    'guard_name' => 'web',
                ],
                [
                    'name' => $permission->value,
                    'guard_name' => 'web',
                ]
            );
        }

        $superAdmin = SpatieRole::updateOrCreate(
            [
                'name' => Role::SuperAdmin->value,
                'guard_name' => 'web',
            ]
        );

        $head = SpatieRole::updateOrCreate(
            [
                'name' => Role::Head->value,
                'guard_name' => 'web',
            ]
        );

        $executor = SpatieRole::updateOrCreate(
            [
                'name' => Role::Executor->value,
                'guard_name' => 'web',
            ]
        );

        $observer = SpatieRole::updateOrCreate(
            [
                'name' => Role::Observer->value,
                'guard_name' => 'web',
            ]
        );

        $auditor = SpatieRole::updateOrCreate(
            [
                'name' => Role::Auditor->value,
                'guard_name' => 'web',
            ]
        );

        $allPermissions = SpatiePermission::query()
            ->where('guard_name', 'web')
            ->get();

        $superAdmin->syncPermissions($allPermissions);

        $head->syncPermissions([
            Permission::DashboardView->value,

            Permission::StaffView->value,

            Permission::TaskView->value,
            Permission::TaskCreate->value,
            Permission::TaskUpdate->value,
            Permission::TaskAssign->value,
            Permission::TaskReassign->value,
            Permission::TaskCancel->value,
            Permission::TaskAccept->value,
            Permission::TaskReopen->value,
            Permission::TaskArchive->value,
            Permission::TaskApproved->value,

            Permission::TaskCommentCreate->value,

            Permission::TaskPunktView->value,
            Permission::TaskPunktCreate->value,
            Permission::TaskPunktUpdate->value,
            Permission::TaskPunktComplete->value,

            Permission::TaskLogView->value,

            Permission::StatisticsView->value,
            Permission::EfficiencyView->value,
        ]);

        $executor->syncPermissions([
            Permission::TaskView->value,
            Permission::TaskUpdate->value,
            // Permission::TaskAssign->value,
            // Permission::TaskReassign->value,
            // Permission::TaskCancel->value,
            Permission::TaskStart->value,
            Permission::TaskSubmit->value,
            Permission::TaskAccept->value,
            // Permission::TaskReopen->value,
            // Permission::TaskArchive->value,
            // Permission::TaskDelete->value,

            Permission::TaskCommentCreate->value,
            // Permission::TaskCommentDelete->value,

            Permission::TaskPunktView->value,
            // Permission::TaskPunktCreate->value,
            // Permission::TaskPunktUpdate->value,
            // Permission::TaskPunktComplete->value,

            Permission::TaskLogView->value,
        ]);

        $observer->syncPermissions([
            Permission::DashboardView->value,
            Permission::TaskView->value,
            Permission::TaskLogView->value,
            Permission::StatisticsView->value,
        ]);

        $auditor->syncPermissions([
            Permission::DashboardView->value,
            Permission::TaskView->value,
            Permission::TaskLogView->value,
            Permission::StatisticsView->value,
            Permission::EfficiencyView->value,
            Permission::AuditView->value,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
