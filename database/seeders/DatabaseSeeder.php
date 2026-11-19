<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            LookupSeeder::class,
            PermissionSeeder::class,
            MenuAndWidgetSeeder::class,
            ServiceDeskSeeder::class,        // Bundle 2: case/chat lookups, bot knowledge, menu, widgets
            DemoOrganizationSeeder::class,   // generic demo tenant; remove for production installs
            ServiceDeskRolesSeeder::class,   // Bundle 2: widgets per role + admin phone (runs after roles exist)
            FilesSeeder::class,              // Bundle 3: file lookups, classification levels, menu, widgets, role grants
            ITOperationsSeeder::class,       // Bundle 4: assets, movements, access register, incidents, IT manager role
            ArchiveSeeder::class,            // Bundle 5: records, retention, disposal, legal references, records manager + compliance officer roles
            ComplianceSeeder::class,         // Bundle 6: backups, training, compliance calendar, audits
            ReportsSeeder::class,            // Bundle 7: reports menu and report permissions per role
            CopsFoundationSeeder::class,
            ProjectsSeeder::class,
            TimeSeeder::class,
            BillingSeeder::class,
            PortalSeeder::class,             // COPS Phase 3            // COPS Phase 2b               // COPS Phase 2a           // COPS Phase 1     // COPS Phase 0: approvals, rules, scope, employees, clients
            HrSeeder::class,                 // COPS Phase 4a: HR lookups, permissions, hr_manager role, employment-change flow
            HrRecruitmentSeeder::class,      // COPS Phase 4b: recruitment lookups, permissions, approval flows, onboarding template
            HrAttendanceSeeder::class,       // COPS Phase 4c: attendance lookups, permissions, correction approval flow
            MeetingsSeeder::class,          // COPS Phase 7a: meeting lookups, permissions, menu
            MeetingsAttendanceSeeder::class, // COPS Phase 7b: attendance statuses and check-in methods
            MeetingsMinutesSeeder::class, // COPS Phase 7c: minutes statuses, visibilities and the chair-approval flow
            MeetingsFollowUpSeeder::class, // COPS Phase 7d: resolution and action statuses, follow-up menu
            EventsSeeder::class, // COPS Phase 7e: event lookups, permissions, menu
            EventsExpensesSeeder::class, // COPS Phase 7e: expense categories, statuses, approval flow
            ProcurementSeeder::class, // COPS Phase 8a: procurement lookups, permissions, menu, approval flows
            ProcurementOrdersSeeder::class, // COPS Phase 8b: purchase order statuses, receiving permission, flow
            ProcurementInvoicesSeeder::class, // COPS Phase 8c: invoice statuses, permissions, menu
            ProcurementRfqSeeder::class, // COPS Phase 8d: RFQ statuses and menu
            ProcurementFilesSeeder::class, // COPS Phase 8e: file link types for quote and invoice documents
            MeetingsOnlineSeeder::class, // COPS Phase 7f: online meeting lookups
            MeetingsCollabSeeder::class, // COPS Phase 7g: poll statuses
            PolishSeeder::class,             // currency list and menu icons
            CodesSeeder::class,              // system rule sets with fixed keys
        ]);
    }
}
