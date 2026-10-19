<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** code => [group, name]. Permissions for later bundles are declared now so the role matrix is complete from day one. */
    public static function all(): array
    {
        return [
            'dashboard.view' => ['dashboard', 'View dashboard'],
            'organization.view' => ['organization', 'View organization profile'],
            'organization.update' => ['organization', 'Update organization profile'],
            'user.view' => ['access', 'View users'],
            'user.create' => ['access', 'Create / invite users'],
            'user.update' => ['access', 'Update users'],
            'user.suspend' => ['access', 'Suspend / reactivate users'],
            'role.view' => ['access', 'View roles and permissions'],
            'role.manage' => ['access', 'Create and configure roles'],
            'lookup.view' => ['reference', 'View lookup tables'],
            'lookup.manage' => ['reference', 'Manage lookup values'],
            'department.view' => ['reference', 'View departments'],
            'department.manage' => ['reference', 'Manage departments'],
            'location.view' => ['reference', 'View locations'],
            'location.manage' => ['reference', 'Manage locations'],
            'audit_log.view' => ['audit', 'View audit log'],
            'data.export' => ['audit', 'Export list data'],
            'data.import' => ['audit', 'Import list data'],
            'case.create' => ['cases', 'Submit cases'],
            'case.view_own' => ['cases', 'View own cases'],
            'case.view_all' => ['cases', 'View all cases'],
            'case.comment' => ['cases', 'Comment on cases'],
            'case.assign' => ['cases', 'Assign cases'],
            'case.update_status' => ['cases', 'Change case status'],
            'case.close' => ['cases', 'Close cases'],
            'case.notify_new' => ['cases', 'Receive alerts (email/SMS) for new cases'],
            'chat.use' => ['chat', 'Use live chat'],
            'chat.moderate' => ['chat', 'Moderate / answer chats'],
            'file.upload' => ['files', 'Upload files'],
            'file.view' => ['files', 'View files'],
            'file.download' => ['files', 'Download files'],
            'file.replace' => ['files', 'Replace files'],
            'file.archive' => ['files', 'Archive files'],
            'file.restore' => ['files', 'Restore archived files'],
            'file.delete_request' => ['files', 'Request file deletion'],
            'file.delete_approve' => ['files', 'Approve / execute file deletion'],
            'file.legal_hold' => ['files', 'Place / release legal hold on files'],
            'archive.view' => ['archive', 'View archive records'],
            'archive.manage' => ['archive', 'Manage archive records'],
            'archive.approve_disposal' => ['archive', 'Approve disposal'],
            'asset.view' => ['assets', 'View IT assets'],
            'asset.manage' => ['assets', 'Manage IT assets'],
            'asset.approve_movement' => ['assets', 'Approve equipment movement'],
            'report.view' => ['reports', 'View reports'],
            'report.export' => ['reports', 'Export reports'],
        ];
    }

    public function run(): void
    {
        foreach (self::all() as $code => [$group, $name]) {
            Permission::updateOrCreate(['code' => $code], ['group_id' => Lookup::id('permission_group', $group), 'name' => $name]);
        }
    }
}
