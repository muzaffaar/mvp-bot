<?php

namespace App\Enums;

enum Permission: string
{
    case DashboardView = 'dashboard.view';

    case StaffView = 'staff.view';
    case StaffCreate = 'staff.create';
    case StaffUpdate = 'staff.update';
    case StaffDelete = 'staff.delete';
    case StaffMerge = 'staff.merge';


    case RoleView = 'role.view';
    case RoleCreate = 'role.create';
    case RoleUpdate = 'role.update';
    case RoleDelete = 'role.delete';

    case PermissionView = 'permission.view';
    case PermissionManage = 'permission.manage';

    case TaskView = 'task.view';
    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskAssign = 'task.assign';
    case TaskReassign = 'task.reassign';
    case TaskCancel = 'task.cancel';
    case TaskStart = 'task.start';
    case TaskSubmit = 'task.submit';
    case TaskAccept = 'task.accept';
    case TaskReopen = 'task.reopen';
    case TaskArchive = 'task.archive';
    case TaskDelete = 'task.delete';
    case TaskApproved = 'task.approved';

    case TaskCommentCreate = 'task.comment.create';
    case TaskCommentDelete = 'task.comment.delete';

    case TaskPunktView = 'task.punkt.view';
    case TaskPunktCreate = 'task.punkt.create';
    case TaskPunktUpdate = 'task.punkt.update';
    case TaskPunktComplete = 'task.punkt.complete';

    case TaskLogView = 'task.log.view';

    case StatisticsView = 'statistics.view';
    case EfficiencyView = 'efficiency.view';

    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'View dashboard',

            self::StaffView => 'View staff',
            self::StaffCreate => 'Create staff',
            self::StaffUpdate => 'Update staff',
            self::StaffDelete => 'Delete staff',
            self::StaffMerge => 'Merge staff',


            self::RoleView => 'View roles',
            self::RoleCreate => 'Create roles',
            self::RoleUpdate => 'Update roles',
            self::RoleDelete => 'Delete roles',

            self::PermissionView => 'View permissions',
            self::PermissionManage => 'Manage permissions',

            self::TaskView => 'View tasks',
            self::TaskCreate => 'Create tasks',
            self::TaskUpdate => 'Update tasks',
            self::TaskAssign => 'Assign tasks',
            self::TaskReassign => 'Reassign tasks',
            self::TaskCancel => 'Cancel tasks',
            self::TaskStart => 'Start tasks',
            self::TaskSubmit => 'Submit tasks',
            self::TaskAccept => 'Accept tasks',
            self::TaskReopen => 'Reopen tasks',
            self::TaskArchive => 'Archive tasks',
            self::TaskDelete => 'Delete tasks',
            self::TaskApproved => 'Approved tasks',

            self::TaskCommentCreate => 'Create task comments',
            self::TaskCommentDelete => 'Delete task comments',

            self::TaskPunktView => 'View task points',
            self::TaskPunktCreate => 'Create task points',
            self::TaskPunktUpdate => 'Update task points',
            self::TaskPunktComplete => 'Complete task points',

            self::TaskLogView => 'View task logs',

            self::StatisticsView => 'View statistics',
            self::EfficiencyView => 'View efficiency',

            self::AuditView => 'View audit logs',
        };
    }
}
