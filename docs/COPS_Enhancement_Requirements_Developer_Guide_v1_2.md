

<!-- Start of picture text -->
7 Dp TI ‘N<br>— \<br>aa =<br>ve<br>uz Ea |;<br>]<br>ae<br>———_NS ‘WorkflowProjects & Billing uPa<br>\ y<br>( «®> [<br>\ a cops E33)<br>—# CORE SERVICES La Se<br>Human sane Vp be WS Meetings<br>& Payrol / ? \\ & Events<br>Procurement Management<br>& Contracts Intelligence<br><!-- End of picture text -->

# **COPS** 

## **E N H A N C E M E N T P R O J E C T** Requirements & Developer Guide 

Project Delivery  •  Human Resources & Payroll  •  Meetings & Events  •  Procurement & Contracts 





<!-- Start of picture text -->
eS<br><!-- End of picture text -->





<!-- Start of picture text -->
©<br><!-- End of picture text -->



**Projects & HR & Meetings & Procurement & Billing Workflow Payroll Events Contracts** 

**Version 1.2  |  Production Requirements Baseline  |  2 November 2026** Prepared as a product and technical implementation baseline 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **Document control** 

|**Item**|**Description**|
|---|---|
|**Document**|COPS Enhancement: Requirements & Developer Guide|
|**Version**|1.2 — Adds configurable rules and policy engine; decisions split into blocking and<br>configurable|
|**Status**|Draft for review and approval|
|**Date**|2 November 2026|
|**Audience**|Product owner, developers, technical lead, HR, finance, legal/compliance reviewers|
|**Normative language**|MUST / MUST NOT = mandatory; SHOULD = recommended unless a written reason is<br>recorded.|





### **Executive summary** 

COPS is to evolve from an operational support platform into a secure, modular Company Operations Platform that coordinates project delivery, employee lifecycle and administration, meetings, decisions, documents, and financial workflows. 

This document defines the initial consolidated requirements baseline for COPS enhancement. It is intended for product owners, developers, testers, administrators, and implementation stakeholders. 

|**4**|**9**|**4**|**8**|
|---|---|---|---|
|business modules (A–D)|delivery phases (0–8)|languages: en, fr, rw, sw|blocking decisions|



#### **How to use this document** 

- Sections 1–3 define product principles and shared foundations applicable to all modules. 

- Sections 4–7 define functional requirements for Projects & Billing, HR & Payroll, Meetings & Events, and Procurement & Contract Management. 

- Sections 8–10 specify architecture, delivery sequencing, quality gates, and the definition of done. 

- Section 3.7 defines the configurable rules and policy engine. Section 11 separates the few blocking decisions from business rules that administrators configure. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **2** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **Contents** 

|**Document control.............................................................................................................2**|
|---|
|**Executive summary..........................................................................................................2**|
|How to use this document.........................................................................................................................2|
|**Contents....................................................................................................................................3**|
|**1. Product vision and scope..............................................................................................5**|
|1.1 Vision................................................................................................................................................5<br>|
|1.2 Strategic outcomes.............................................................................................................................5|
|1.3 Target modules.................................................................................................................................. 5|
|**2. Existing baseline and reuse requirements....................................................................6**|
|2.1 Repositories....................................................................................................................................... 6|
|<br>2.2 Stack identified...................................................................................................................................6|
|2.3 Existing capabilities to reuse.................................................................................................................6|
|<br>2.4 Binding engineering rules.....................................................................................................................6|
|**3. Cross-cutting foundations.............................................................................................7**|
|3.1 Generic approval engine......................................................................................................................7|
|3.2 Department-scoped access...................................................................................................................7<br>3.3 Employee master and user account.......................................................................................................8|
|34 Client and external identities8|
|.    ................................................................................................................<br>3.5 Money, time, and files.........................................................................................................................8|
|3.6 Shared notification comments and audit...............................................................................................8|
|, ,<br>3.7 Configurable business rules and policy engine.........................................................................................9|
|**4. Module A — Project & Workflow Management.....................................................................11**|
|4.1 Objective.........................................................................................................................................11|
|4.2 Roles and default responsibility model..................................................................................................11|
|<br>4.3 Project lifecycle and gates.................................................................................................................. 12|
|4.4 Multi-department participation............................................................................................................ 12|
|<br>4.5 Task structure and workflow...............................................................................................................12|
|4.6 Timer and timesheets........................................................................................................................ 12<br>|
|4.7 Billing and invoices............................................................................................................................13<br>48 Client portal13|
|.  .....................................................................................................................................<br>4.9 Comments, attachments, and deletion.................................................................................................13|
|4.10 Acceptance criteria..........................................................................................................................14|
|**5. Module B — Human Resources & Payroll..............................................................................15**|
|5.1 Recruitment.....................................................................................................................................15<br>|
|5.2 Employee master, categories, and career path......................................................................................15|
|5.3 Attendance and working time..............................................................................................................15<br>|
|5.4 Leave management: law plus company policy.......................................................................................15|
|5.5 Business trips and missions................................................................................................................ 16<br>|
|5.6 Payroll: configurable calculation engine................................................................................................16|
|5.7 HR security...................................................................................................................................... 17|
|5.8 HR acceptance criteria.......................................................................................................................18|
|**6. Module C — Meetings & Events............................................................................................19**|
|6.1 Lifecycle...........................................................................................................................................19|
|6.2 Scheduling and logistics.....................................................................................................................19<br>|
|6.3 Agenda............................................................................................................................................19|
|64 Invitations RSVP and reminders19|
|. , ,  ........................................................................................................<br>6.5 Attendance.......................................................................................................................................20|
|6.6 Minutes, review, publication...............................................................................................................20<br>|
|6.7 Resolutions and action items..............................................................................................................20<br>6.8 Confidentiality and acceptance criteria.................................................................................................20|
|**7. Module D — Procurement & Contract Management..............................................................21**|
|7.1 Purpose and applicability....................................................................................................................21<br>|
|7.2 Guiding principles..............................................................................................................................21|
|7.3 Roles and segregation of duties...........................................................................................................21<br>|
|7.4 End-to-end lifecycle...........................................................................................................................22|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **3** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

|7.5 Sourcing methods and policy matrix....................................................................................................23<br>|
|---|
|7.6 Supplier master and due diligence.......................................................................................................23|
|7.7 Contract register, amendments, and performance.................................................................................23<br>|
|7.8 Records, controls, and reporting..........................................................................................................24|
|7.9 Proposed procurement entities (logical only).........................................................................................24<br>|
|7.10 Procurement acceptance criteria........................................................................................................24|
|7.11 Legal and international reference framework.......................................................................................25|
|**8. Architecture and implementation standards...............................................................26**|
|8.1 Architecture decision.........................................................................................................................26<br>|
|8.2 Backend organization.........................................................................................................................26|
|8.3 API conventions................................................................................................................................ 26|
|8.4 Database design rules........................................................................................................................26|
|8.5 Infrastructure and performance..........................................................................................................27<br>|
|8.6 Security requirements........................................................................................................................27<br>|
|8.7 CI and quality tooling........................................................................................................................ 27|
|**9. Phased delivery roadmap............................................................................................28**<br><br>|
|**10. Quality gates and definition of done.........................................................................29**<br><br>|
|**11. Product-owner decisions and go-live checklist.........................................................30**<br>|
|11.1 Blocking decisions (needed before the affected work starts)..................................................................30|
|11.2 Business rules seeded with defaults and configured by administrators....................................................30<br>|
|**Appendix A — Proposed data entities..............................................................................32**<br> <br>)|
|A.1 Shared foundation.............................................................................................................................32|
|A.2 Projects and billing............................................................................................................................32<br>|
|A.3 HR.................................................................................................................................................. 32|
|A.4 Meetings and events..........................................................................................................................32|
|A.5 Procurement & contracts....................................................................................................................33<br>|
|**Appendix B — Design decisions adopted in this baseline................................................34**<br><br>|
|**Appendix C — Legal and regulatory validation caveat.....................................................35**<br>|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **4** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **1. Product vision and scope** 

#### **1.1 Vision** 

COPS shall provide a secure, modular, multilingual, configurable platform for managing organizational operations, projects, people, meetings, controlled records, approvals, and management oversight— using a common identity, permissions, workflow, audit, notification, and reporting foundation. 

#### **1.2 Strategic outcomes** 

- Centralize operational work, responsibilities, records, and decisions. 

- Strengthen accountability through scoped permissions, approval controls, and auditable history. 

- Coordinate multiple departments and external clients within shared projects. 

- Track capacity, workload, time, employee development, and organizational commitments. 

- Automate administrative and financial workflows while preserving segregation of duties. 

- Provide management with timely operational and financial indicators. 

- Protect confidential employee, client, contractual, and financial data. 

- Support compliance through configurable and effective-dated rules rather than hard-coded assumptions. 

#### **1.3 Target modules** 















|**Module**|**Primary capabilities**|
|---|---|
|**COPS Core**|Identity, organization, departments, permissions, approval engine, clients,<br>documents, audit, notifications, lookup configuration, reporting|
|**Projects & Workflow**|Projects, contracts, departments, tasks, milestones, dependencies, supervision,<br>timer, timesheets, deliverables, client portal|
|**Billing**|Rate cards, approved-time billing, fixed/milestone billing, invoices, payments,<br>adjustments, reconciliation|
|**Human Resources**|Recruitment, employee master, contracts, career path, attendance, leave,<br>travel, performance, payroll|
|**Meetings & Events**|Scheduling, rooms, invitations, agendas, attendance, minutes, comments,<br>resolutions, action items, publication|
|**Procurement &**<br>**Contracts**|Procurement plans, requisitions, sourcing events, bid evaluation, awards,<br>purchase orders, receipts, three-way invoice match, supplier master, contract<br>register, amendments, performance|
|**Management**<br>**Intelligence**|Cross-module dashboards, KPIs, risks, workload, financial and operational<br>reports|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **5** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **2. Existing baseline and reuse requirements** 

#### **2.1 Repositories** 

Backend: https://github.com/Dabani/company-operation-system Frontend: https://github.com/Dabani/company-operations-systems-frontend 

#### **2.2 Stack identified** 

|**Layer**|**Detected baseline**|**Direction**|||
|---|---|---|---|---|
|**API**|Laravel 13, PHP 8.4, MySQL 8.4, Sanctum cookie sessions, Reverb,<br>queues, scheduler, PHPUnit|Retain|||
|**Web**|React 19, TypeScript, Vite, Mantine 9, TanStack Query, React<br>Router, i18next, Echo, PWA, Vitest|Retain|||
|**Reporting**|Laravel Excel, Dompdf, existing reporting framework|Extend|||
|**UI components**|DataList, Tiptap rich text, Dropzone, Mantine notifications/charts|Reuse|||
|**Security /**<br>**operations**|Dynamic roles and permissions, AuditLogger, Notifier, departments,<br>lookups, organization settings|Extend;<br>duplicate|do|not|



#### **2.3 Existing capabilities to reuse** 

- Controlled Files: ownership, owner-only deletion, formal deletion request for non-owners, file history. 

- Case comments: internal/public visibility model; adapt where suitable for project/task and meeting collaboration. 

- Notifier: e-mail, SMS, in-app, and real-time notifications. 

- AuditLogger: sensitive views, actions, exports, workflow changes. 

- Dynamic role/permission matrix and department structure. 

- Lookup/configuration framework and organization settings. 

- Reports framework with separate view and export permissions. 

- DataList, Tiptap, existing authentication activation and CSRF/session conventions. 

#### **2.4 Binding engineering rules** 

**1.** Every protected route MUST authorize on the server. UI hiding is not a security control. 

**2.** All tenant-owned business tables MUST include organization_id and use the existing organization scope (BelongsToOrganization or equivalent). 

**3.** No database ENUM columns and no JSON columns for core business structures or configurable rule data. Use relational tables and lookups. 

**4.** Audit sensitive views and exports as well as create/update/delete and approval actions. 

**5.** Translate user-facing strings in English (en), French (fr), Kinyarwanda (rw), and Swahili (sw), both server-side and frontend. 

**6.** Responsive design starts at 360 px; keyboard accessibility is required. 

**7.** Follow the existing Verdana and brand palette: blue #3773b8 and green #1c9d67, unless design-system tokens supersede. 

**8.** Respect the first-load JavaScript budget of 400 KB gzipped and existing npm run build:check. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **6** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **3. Cross-cutting foundations** 

Build these shared services first. They are dependencies for all four modules and prevent inconsistent approval, access, employee, client, money, time, and document implementations. 

#### **3.1 Generic approval engine** 

Implement one reusable approval engine for task review, timesheets, leave, trips, recruitment, promotion, contracts, invoice issuance, payroll, deletion requests, and report publication. 

|**Entity**|**Purpose**|
|---|---|
|**approval_flows**|Workflow definition for a subject type and organizational context|
|**approval_steps**|Ordered steps resolved by role, department head, line manager, or named user|
|**approval_requests**|A specific submitted approval instance and current state|
|**approval_actions**|Immutable decisions: actor, time, decision, comment, step, delegation context|



- Support sequential approvals initially; parallel approval may be added where needed. 

- Support delegation/substitute approvers, configurable escalation, reminders, and effective workflow versions. 

- Prevent a requester/preparer from approving their own request. 

- Statuses and step types are lookup-driven. 

- All transitions are validated server-side, transaction-safe, and audited. 



<!-- Start of picture text -->
Generic approval engine: one reusable workflow for every subject type<br>=| (0 e e<br>TimesheetPre step 1 step 2 step 3 rv)action{Approvedexecute<br>Payroll - Minutes- Invoice Line manager Department head Finance / MD<br>¥. 7<br>N. 7<br>SS a<br>_— a<br>~~sngject / return with mandatory commenb=“~<br>Co} Delegation / substitute @ Escalation & reminders Qg No self-approval ta} Immutable audit<br><!-- End of picture text -->

_Figure 1. Generic approval engine: one workflow definition serves every subject type, with delegation, escalation and a full audit trail._ 

#### **3.2 Department-scoped access** 

Implement user_department_assignments with scope levels such as own, team, department, and organization. Provide one reusable query scope and Laravel Policy helper. Directors see projects, tasks, and records only within assigned departments. Every list, detail, export, and mutation endpoint must apply scope. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **7** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



<!-- Start of picture text -->
ORGANIZATION ORGANIZATION<br>Managing director, administrator<br>DEPARTMENT organizationid scope; full tenant data<br>DEPARTMENT<br>Director<br>Only departments assigned in user_department_assignments<br>Own TEAM<br>records and Team leader, manager<br>assignments Own team members and their work<br>own<br>Employee, client contact<br>(Own records only; clients see only own projects<br><!-- End of picture text -->

_Figure 2. Department-scoped access: one reusable scope helper resolves what each role can see._ 

#### **3.3 Employee master and user account** 

hr_employees is the single canonical person record. A COPS user account is optional and linked oneto-one. Interns, casual workers, volunteers, and staff without system access remain manageable as employees. Project assignment, attendance, employment, and payroll reference the employee record —not a user login. 

#### **3.4 Client and external identities** 

Create clients and client_contacts, plus a distinct external client identity/guard or equivalent authentication boundary. External users MUST NOT share the internal permission matrix. Apply clientscoped query access to every portal resource. Guessing another client’s project identifier must return 404. Test cross-client access automatically. 

#### **3.5 Money, time, and files** 

|**Concern**|**Required rule**|
|---|---|
|**Money**|DECIMAL(15,2) and currency code per financial document; base currency configured by<br>organization. Display precision and rounding configurable. Central MoneyService.|
|**Time**|Persist UTC timestamps; display in organization timezone (Africa/Kigali default). Timers<br>and attendance use server timestamps only.|
|**Files**|Reuse Controlled Files. Private storage; signed expiring URLs in production. ClamAV<br>scan before downloadable status, mandatory before client uploads.|
|**Sensitive HR fields**|Encrypt national ID, bank account, salary and other sensitive fields at rest (e.g. Laravel<br>encrypted casts); hide from lists; audit every read.|



#### **3.6 Shared notification, comments, and audit** 

- Reuse Notifier channels and queues; make notification preferences configurable where appropriate. 

- Comments support internal/client-visible or restricted audience, sanitized rich text, mentions, and audit history. 

- Record actor, action, resource, organization, timestamp, and appropriate change context. Avoid copying secrets or excessive personal data into logs. 

- Exports and bulk downloads must be separately permissioned and audited. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **8** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

#### **3.7 Configurable business rules and policy engine** 

Most business rules differ between organizations and change over time: approval chains, monetary thresholds, procurement methods, leave policy, retention periods, employee categories and portal security. They MUST NOT be hard-coded or settled through one-off questions to the product owner. COPS provides one shared rules and policy engine that authorized administrators configure, with sensible seeded defaults, without code changes. 



<!-- Start of picture text -->
Business rules are data: versioned, approved, effective-dated and resolved at runtime<br>Transaction Rule Stored on<br>amount - department ‘most specific active ‘approvers - thresholds ruleversion id kept<br>method - category Tule Version wins required documents later changesnever apply<br>RULE VERSION LIFECYCLE<br>Pending Superseded /<br>approval expired<br>CONFIGURABLE BY AUTHORIZED ADMINISTRATORS MANDATORY, NOT CONFIGURABLE<br>‘Approval chains thresholds - procurement methods : leave policy ‘Audit tral - no self approval: segregation of duties<br>retention periods : categories : portal security : templates, statutory floors - immutable completed records -RBAC<br><!-- End of picture text -->

_Figure 3. Configurable rules engine: rules are resolved at runtime, versioned and approved, and remain mandatory where safeguards cannot be switched off._ 

##### **3.7.1 What administrators configure** 

|**Domain**|**Configured as**|**Examples**|
|---|---|---|
|**Approval chains**|Rule: amount, department, subject<br>type, method and budget status<br>determine required approvers|Purchase above a threshold needs director then<br>managing director|
|**Procurement policy**|Policy profiles with methods,<br>thresholds,<br>quotation<br>counts,<br>evaluation criteria and required<br>documents|Private-company<br>profile,<br>public-procurement<br>profile, donor-funded profile|
|**Leave and attendance**|Company policy above the statutory<br>floor|Recommended periods, minimum and maximum<br>run, carry-over|
|**Payroll**|Effective-dated schemes, brackets<br>and category assignments|Rates by employee category|
|**Billing and portal**|Billing models, currencies, client<br>visibility, portal security|Progress-only portal; MFA for client users|
|**Records and**<br>**notifications**|Retention schedules, reminders,<br>templates, calendars|Retention by record class; reminder timing|



##### **3.7.2 Rule lifecycle and governance** 

- Every rule set has an owner and moves through Draft, Impact check (what-if simulation on sample transactions), Pending approval, Active, and Superseded or Expired. 

- Rules are effective-dated and versioned. A transaction stores the rule version used, so later changes never rewrite history; new rules apply only to new transactions unless a migration is approved. 

- Activating or changing a rule requires approval through the approval engine, by someone other than its author. Configuration permissions are separate from general administration permissions. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **9** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

- Conflicts and gaps (overlapping rules, no applicable rule, thresholds out of order) are detected on save and block activation. When no rule applies, the safe default is that the transaction requires explicit approval. 

- Seeded templates (for example Rwandan private-sector procurement, leave and payroll profiles) are flagged Unverified until the named legal or finance owner signs them off; the sign-off is stored with the rule version. 

##### **3.7.3 Mandatory safeguards (not configurable)** 

- Complete audit trail of configuration changes and of every transaction decision; it cannot be disabled or edited. 

- No self-approval and segregation of duties, including for configuration changes. 

- Approval evidence (who, when, which rule version, comment) and immutable completed transactions, corrected only by adjustment or credit documents. 

- Role-based and department-scoped access to confidential data. 

- Statutory floors and ceilings held in legal_parameters with a source reference and sign-off. COPS enforces stored limits (for example a company leave policy cannot grant less than the statutory minimum); it cannot itself certify that a policy is lawful, so activation of a legal-profile rule records a human sign-off. 

##### **3.7.4 Developer instructions** 

- Model rules relationally (rule_sets, rule_versions, rule_conditions, rule_outcomes, rule_activations, legal_parameters); no JSON columns and no enums. 

- Build a single RuleResolver service used by approvals, procurement, leave, payroll and the portal. Resolution is deterministic: the most specific condition set wins, then the highest priority, then the latest effective version. 

- Cache resolved rules per organization with invalidation on activation; never cache across organizations. 

- Deliver the engine in Phase 0 with the approval engine; later modules consume it rather than introducing their own settings tables. 

- Test with golden scenarios: boundary amounts, overlapping effective dates, missing rules, delegation, and grandfathered transactions. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **10** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **4. Module A — Project & Workflow Management** 



#### **4.1 Objective** 

Manage a project from client negotiation and contract through coordinated departmental execution, task acceptance, delivery, time-based or agreed billing, payment recovery, and controlled closure. 

#### **4.2 Roles and default responsibility model** 

|**Actor / department**|**Default responsibilities**|
|---|---|
|**Team leader**|Initiate projects within own department; create and define tasks; assign and supervise<br>team members.|
|**Manager / project**<br>**manager**|Team-leader capabilities plus task review/accept/return; team timesheet approval;<br>project planning and departmental role assignment.|
|**Director**|Read-only tracking of projects, tasks, time, dashboards, and reports in assigned<br>departments; budget-change approval if configured.|
|**Managing director**|Sign client contracts; approve invoices above configured threshold; authorize project<br>closure.|
|**Administration /**<br>**commercial**|Client relationship, negotiation, contract drafting, and contract upload.|
|**Technical / delivery**|Execute terms of reference, tasks, deliverables, and time tracking.|
|**Finance**|Rate cards, invoice preparation and issue, payment recording, recovery follow-up, and<br>reconciliation.|
|**External client**|View own project progress, client-visible comments and deliverables; comment and<br>upload permitted files.|



These are seeded defaults, not hard-coded roles. The dynamic permission matrix and project department roles are configurable. Effective access is the intersection of global permission, department assignment, and project role. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **11** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

#### **4.3 Project lifecycle and gates** 



<!-- Start of picture text -->
MD signs contract All tasks accepted Draft invoice _Finance confirms,<br>toactivate before delivery created, then issued MD closes<br>Negotiation Contrncepending Active Delivered Invoiced Closed<br>(on hold (optional)<br>e Cancelled may be reached from any state before Closed. Transitions and permissions are stored in project_status transitions.<br>Every transition is audited and notifies the team (and the client when marked client-visible).<br><!-- End of picture text -->

_Figure 4. Project lifecycle with delivery, invoice, payment and closure gates._ 

**Lookup-driven lifecycle:** Draft → Negotiation → Contract pending signature → Active → On hold (where permitted)  Delivered  Invoiced  Closed. Cancelled may be reached from any state before → → → Closed. Allowed transitions and required permissions are stored in project_status_transitions. 

- Only projects.contract.sign may move Contract pending signature → Active; a signed contract must be attached. 

- A project cannot become Delivered while any task is open, blocked, or awaiting review. 

- Delivery triggers creation of a draft invoice (not automatic issuance). 

- Only projects.close may close a project. Closure requires all invoices issued and Finance confirmation that no disputed items remain, or an explicitly authorized exception. 

- Closing locks tasks, time entries, and project attachments according to retention policy. 

- Every transition is audited and notifies the team and client when the transition is client-visible. 

#### **4.4 Multi-department participation** 

Each project has one owning department and any number of participating departments. project_departments records department and project_role (commercial, delivery, billing, etc.). Department roles constrain actions: Finance may invoice but cannot modify technical tasks. Access is the intersection of global permissions and project-specific department role. 

#### **4.5 Task structure and workflow** 

- Hierarchy: Project 

   - → optional milestone → task → optional subtask. 

- Fields: title, sanitized rich description, assignees, reviewer, priority, due date, estimate in hours, billable flag, rate source, department, status, and deliverables. 

- Lookup statuses: To do, In progress, Blocked, In review, Returned, Accepted, Cancelled. 

- Only assigned reviewer/authorized manager may Accept or Return. Return requires a comment. 

- Views: DataList, Kanban, calendar. Gantt is optional and lazy-loaded. 

- Finish-to-start dependencies warn by default; strict blocking is an organization setting. 

- Team leaders/managers see assigned effort versus employee capacity. 

#### **4.6 Timer and timesheets** 

**1.** Start, pause, resume, and stop from task screen and persistent header timer. 

**2.** Persist start/stop/pause segments on server; elapsed time survives browser close, device switching, and network loss. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **12** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

**3.** Default: one running timer per user. Starting another prompts to stop the existing timer (organization setting). 

**4.** Manual entries require reason, are flagged manual, and can be disabled by organization policy. 

**5.** Reject overlapping entries; validate time segments and UTC timestamps server-side. 

**6.** Configurable idle warning; auto-stop forgotten timers after maximum duration and flag for correction. 

**7.** Entry states: Draft, Submitted, Approved, Invoiced. Managers approve weekly timesheets. 

**8.** Approved/invoiced entries are immutable; corrections use adjustment entries with reason and audit. 

**9.** Task and time entry have billable/non-billable flags. Rates come from effective-dated rate cards per role, employee, or task. 

**10.** Configurable rounding (e.g. 5/15-minute increments) is applied once at invoice generation—not repeatedly in timer or timesheet layers. 

#### **4.7 Billing and invoices** 

|**Requirement**|**Specification**|
|---|---|
|**Billing models**|Lookup-driven: Time & Materials, Fixed Price, Milestone Based; mixed models if product<br>owner confirms.|
|**Invoice trigger**|Project reaches Delivered  system creates draft invoice. Finance reviews and adds/edit<br>→<br>non-time lines.|
|**Approval**|Invoice above configured threshold routes to managing director via approval engine.|
|**Statuses**|Draft, Pending approval, Issued, Partially paid, Paid, Overdue, Cancelled.|
|**Payment**|Record payments against invoice; partial payments, balances, due dates, reconciliation,<br>scheduler reminders.|
|**PDF**|Dompdf template: invoice number, client details, tax lines, currency, payment terms,<br>and time breakdown annex.|
|**Tax**|Effective-dated configurable VAT/tax rate. Validate RRA EBM/e-invoicing obligations<br>before production.|
|**Corrections**|Invoices are never deleted. Correct through credit note or authorized adjustment.|



Approved billable time is the source for time-and-materials lines. Store invoice lines, source timeentry references, applied rates, rounding, tax parameters, and calculation snapshots so totals are reproducible years later. 

#### **4.8 Client portal** 

- Separate portal area and external authentication boundary. Client list, status, milestones, task progress percentage/status only, deliverables, client-visible comments, and invoice/payment status when enabled. 

- Clients never see internal comments, internal time entries, employee rates, internal costs, HR data, or other clients. 

- Every comment and attachment has a visibility flag; default is internal. 

- Client onboarding via e-mail invitation, reusing activation flow. 

- Client uploads are virus-scanned; permitted types, sizes, and retention are configured. 

#### **4.9 Comments, attachments, and deletion** 

Reuse sanitized Tiptap rich-text pipeline, comments, mentions, and Notifier. Attachments use Controlled Files: owner-only deletion; non-owner submits formal request routed to administrator; 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **13** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

retain full file history. Closed project files are read-only. Block deletion for legal hold, retention, or active dispute. 

#### **4.10 Acceptance criteria** 

- Director cannot view any project outside assigned departments; test every list, detail, export, and action endpoint. 

- Client cannot access another client project by ID guessing; response is 404. 

- Timer persists after browser closure and computes elapsed time correctly from server timestamps. 

- Invoice total is reproducible from approved billable entries, stored rates, rounding, taxes, and invoice lines. 

- Non-owner attachment deletion creates an approval/request record and does not delete file. 

- Project cannot be Delivered with unresolved tasks/reviews; cannot be Closed with unissued invoices or disputed items unless authorized exception. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **14** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **5. Module B — Human Resources & Payroll** 



#### **5.1 Recruitment** 

**Workflow:** workforce requisition (department, position, headcount, justification) → approval → vacancy/posting → applicants → screening → interview/assessment → selection recommendation → approval → offer → contract → employee conversion and onboarding checklist 

- Applicant stages are lookup-driven: Applied, Screened, Interview, Assessment, Offer, Hired, Rejected. 

- Interview scheduling, panel, scorecards, evaluations, reference checks, offer letter PDF. 

- One-click conversion of hired applicant to hr_employees with onboarding tasks. 

- Applicant personal data retention and deletion periods are configurable and reviewed. 

#### **5.2 Employee master, categories, and career path** 

hr_employees is the canonical employee/person record; COPS account is optional. Category is extensible via lookup: Intern, Probation, Contracted, Casual, Volunteer, Consultant/other as confirmed. Category may define default contract type, leave eligibility, payroll rule assignment, and attendance requirement. 

- Employment history is effective-dated: category, position, grade, department, manager, and salary. Never overwrite past values; add dated records. 

- Position and grade framework; career progression criteria; probation review and contract expiry reminders. 

- Promotion, confirmation, and salary changes route through approval engine. 

- Employee documents and sensitive data use restricted access, encryption, and access audit. 

- Performance management: periodic appraisal cycles linked to career progression and confirmation decisions (performance_reviews). 

#### **5.3 Attendance and working time** 

- Clock in/out through web or mobile PWA; import from biometric device or spreadsheet. 

- Shifts, schedules, public holidays, absence detection against approved leave, lateness, overtime, and monthly summaries. 

- Corrections require reason and approval. Attendance feeds payroll only after validation. 

- Geolocation/IP restrictions are optional; if enabled, disclose to staff and obtain legal review. 

#### **5.4 Leave management: law plus company policy** 

Build leave as a versioned policy engine, not hard-coded day counts. Legal parameters must be confirmed by Rwandan counsel/HR before go-live. Separate statutory entitlement, contract/collective agreement, organizational benefit, and administrative scheduling policy. 

|**Topic**|**Baseline / implementation note**|
|---|---|
|**Governing framework**|Law No. 66/2018 regulating labour in Rwanda, amended by Law No. 027/2023; confirm<br>current consolidated text and subordinate orders.|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **15** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

|**Topic**|**Baseline / implementation note**|
|---|---|
|**Annual leave**|Supplement describes 18 working days after one year (1.5/month), +1 day per 3 years<br>capped at 21; pro-rata below 45 hours/week; 24 days for workers aged 16–18. Legal<br>review required before seed.|
|**Leave types**|Annual, maternity, paternity, sick, circumstantial, public holidays, authorized absence;<br>extensible lookup.|
|**Compensation**|Compensatory leave where required for public holiday during annual leave or work<br>performed during leave—validate exact application.|
|**Sick leave**|Supplement notes short-term up to 15 days and longer-term medical committee rules;<br>confirm current statutory text and procedures.|
|**Maternity / paternity**|2023 law delegates day counts to ministerial order; secondary sources conflict<br>(maternity often cited as 14 weeks; paternity counts vary). Do not hard-code<br>unresolved values.|
|**Pregnancy protection**|Supplement references prohibition on dismissal because of pregnancy (Article 24 bis);<br>legal reviewer confirms precise citation and current wording.|



Implement legal_leave_rules with valid_from, valid_to, rule value, unit, employee eligibility, source citation, verification status, and approver. Unverified values must be visibly flagged and blocked from production activation. 

##### **5.4.1 Company policy layer** 

- Recommended leave periods and blackout periods (e.g. year-end). 

- Minimum consecutive days and maximum days per request; minimum required annual block where lawful. 

- Maximum days per period, carry-over limit and expiry, notice period, maximum simultaneous absences per team. 

- Eligibility by employee category; interns/volunteers may have separate contractual or policy treatment. 

- Approval roles, delegation, conflict warnings, team leave calendar, accrual, balances, transactions, and adjustments. 

- Policy validation must ensure it never grants less than applicable statutory/contractual entitlement. 

##### **5.4.2 International compliance review** 

Review relevant ILO standards on holidays with pay and maternity protection; EAC commitments for cross-border staff; Rwanda data protection law; and GDPR only where its territorial scope applies. Record the legal review in project documentation. Code cannot determine legal applicability. 

#### **5.5 Business trips and missions** 

- Request: purpose, destination, dates, employees, linked project/meeting, budget estimate, itinerary. 

- Approval, per diem/transport rate tables, advances, booking details, mission authorization. 

- Return report, expense declaration with receipts, retirement/settlement against advance. 

- Flag unretired advances. Payroll deduction only where lawful and employee consent is recorded. 

#### **5.6 Payroll: configurable calculation engine** 

Taxes, statutory contributions, retentions, and benefits must be configuration applied by employee category and, where approved, individual exception. Do not hard-code rates or assume category exemptions 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **16** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

|**Relational component**|**Purpose**|
|---|---|
|**pay_components**|Earnings/deductions: basic, allowance, overtime, bonus, per diem; taxable and<br>pensionable flags.|
|**statutory_schemes**|PAYE, pension, maternity fund, health insurance, occupational hazards;<br>employee/employer rates, calculation base, caps, effective dates.|
|**tax_brackets**|Scheme, lower/upper bound, rate, valid_from/valid_to.|
|**category_scheme_assignment**<br>**s**|Scheme/component applicability per employee category, plus approved<br>employee exceptions.|
|**payroll_runs / payslips /**<br>**payslip_lines**|Persist every calculated line and its inputs/rate/source so historical payslips are<br>reproducible.|



**Rate governance:** secondary publications conflict on Rwandan PAYE, RSSB pension, health contributions, and thresholds. Seed production values only from current official RRA/RSSB notices and the company accountant’s signed schedule. Record source, effective date, preparer, and second-person approval for every rate change. 

##### **5.6.1 Payroll lifecycle and controls** 

Draft → Calculated → Reviewed (HR) → Approved (Finance, then Managing Director where required) → Locked → Paid → Declared 



<!-- Start of picture text -->
Dry-run available: nothing persisted Locked = immutable; fix via adjustment run<br>Reviewed Approved i<br>Preparer cannot approve (segregation of duties) - Two-month parallel run must match the accountant exactly before go-live<br><!-- End of picture text -->

_Figure 5. Payroll run lifecycle and controls._ 

- Preparer cannot approve; enforce segregation of duties. 

- Locked payroll is immutable; corrections occur in next run or adjustment run. 

- Payslip PDF and secure employee self-service; e-mail delivery only under approved policy. 

- Bank transfer export format must be agreed with the company bank. 

- Provide PAYE/RSSB declaration exports, accounting payroll journal, and dry-run calculation that does not persist. 

- Confirm statutory declaration/payment deadlines against official sources before scheduler configuration. 

- Category-specific treatment (intern stipend, volunteer allowance, casual hourly/daily, probation salary) must be confirmed by accountant; no assumed exemption. 

- Two-month parallel run must match approved manual payroll to the franc before production. 

#### **5.7 HR security** 

- HR permissions are separate from general management permissions. 

- Salary, bank, ID, medical and recruitment information are field/resource restricted. 

- Audit sensitive reads and all HR data exports. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **17** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

- Payroll preparer cannot approve; payroll approvals require defined independent roles. 

- Employee sees own permitted profile, leave, attendance, and payslips only. 

#### **5.8 HR acceptance criteria** 

- Employment changes preserve effective-dated history and historical payroll reproducibility. 

- Leave balance calculations pass legal/HR-approved worked examples and cannot violate statutory floor. 

- Attendance corrections are auditable and do not silently rewrite payroll after lock. 

- Payroll dry-run produces no persisted records; locked run cannot be edited. 

- Two consecutive parallel payroll periods match accountant-approved calculations exactly. 

- Unauthorized user cannot access another employee’s sensitive fields or payslip. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **18** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **6. Module C — Meetings & Events** 



<!-- Start of picture text -->
cae | |<br>Meetings & Events<br><!-- End of picture text -->

#### **6.1 Lifecycle** 

Plan → invite → prepare agenda → hold meeting/event → record attendance → draft minutes/report → review/comments → chair approval → publish/distribute → track resolutions and action items → close 



<!-- Start of picture text -->
fl 2 3 4 5<br>Plan Invite Agenda Hold Attendance<br>7<br>!<br>6 7 8 9 10)<br>Draft minutes Chair approves Publish Follow up<br>Resolutions convert to project tasks or compliance duties and feed the follow-up dashboard<br><!-- End of picture text -->

_Figure 6. Meeting and event lifecycle from planning to follow-up of resolutions._ 

#### **6.2 Scheduling and logistics** 

- Meeting types lookup: board, executive, departmental, project, training, event, and extensible categories. 

- Title, purpose, date/time/duration, venue or online link, organizer, chair, secretary, facilitator, participants, external guests, confidentiality, project/department link. 

- Recurring meetings and room booking with conflict detection. 

- Calendar availability integration may be added; do not assume Google Calendar connection. 

#### **6.3 Agenda** 

- Ordered agenda items with owner/presenter, allocated time, priority, expected decision, and attached papers. 

- Draft, circulate, revise, and approve agenda before meeting. 

- Confidential agenda items enforce item-level visibility where necessary. 

#### **6.4 Invitations, RSVP, and reminders** 

- Invite users, groups, departments, and external guests. 

- RSVP: accept, decline, propose another time; track response and deadline. 

- Generate ICS calendar invitation. 

- Scheduler sends configurable reminders (e.g. 24 hours and 1 hour) through e-mail, SMS, and inapp channels. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **19** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

- Schedule updates/cancellations send appropriate notices and preserve history. 

#### **6.5 Attendance** 

- Expected attendance list generated from invitees. 

- Secretary check-in; optional self-check-in via QR/link; correction after event with audit. 

- Statuses: present, absent, excused, late, online (lookup-driven). 

- Attendance sheet export to PDF and Excel; signatures/acknowledgement if required. 

#### **6.6 Minutes, review, publication** 

- Secretary drafts in Tiptap from agenda template: attendance/apologies, discussions, decisions, resolutions, actions, responsible parties, due dates, closing. 

- Share draft with authorized participants only; allow comments/inline feedback. 

- Chair approves through approval engine. 

- Publish by visibility: participants, department, organization, named recipients. 

- Approved publication is immutable; corrections create a new version and preserve prior versions. 

- Distribute report with controlled PDF copy and notification to relevant recipients. 

#### **6.7 Resolutions and action items** 

- Resolution number, text, meeting/agenda reference, responsible person/department, due date, status, supporting files. 

- Convert resolution to project task or compliance duty with link back to meeting. 

- Overdue resolutions trigger reminders and appear in follow-up dashboard. 

- Track action owner, due date, status, completion evidence, and closure. 

#### **6.8 Confidentiality and acceptance criteria** 

- Draft minutes visible only to secretary, chair, and authorized review participants. 

- Confidential meeting existence/minutes hidden from non-invitees where configured. 

- Attachments follow Controlled Files rules. 

- Every state change, approval, publication, distribution, and revision is audited. 

- Complete cycle from invitation through approved, published minutes and tracked action items passes E2E test. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **20** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **7. Module D — Procurement & Contract Management** 



#### **7.1 Purpose and applicability** 

COPS should provide a company-wide Procurement & Contract Management module to ensure purchases are planned, justified, competitively sourced where appropriate, approved within delegated authority, received/accepted, matched to supporting documents, and handed to Finance for payment. It should support goods, works, consultancy, and non-consultancy services, including low-value purchases and recurring/framework arrangements. 

**Legal applicability:** Rwanda’s Law No. 031/2022 of 21 November 2022 governing public procurement and Ministerial Order No. 001/23/10/TC of 10 October 2023 regulate public procurement by covered public entities. A private company is not automatically subject to the entire public procurement regime solely because it purchases goods or services. Applicability must be determined by legal status, public funds, donor/financing agreements, sector rules, contract obligations, and other relevant instruments. COPS must support (a) configurable internal corporate procurement policy and (b) a separately configured regulated-procurement profile where applicable. Do not label private-company workflows “RPPA-compliant” without legal review. 

#### **7.2 Guiding principles** 

Configure principles aligned with Rwanda’s public-procurement framework where applicable and recognized international practice: transparency and traceability; competition and proportionality; economy, value for money and lifecycle cost; fairness, equal treatment and confidentiality; integrity and conflict-of-interest management; accountability and segregation of duties; sustainability and responsible sourcing where relevant; records retention, data protection, and defensible decisions. 

#### **7.3 Roles and segregation of duties** 

|**Role**|**Responsibilities and controls**|
|---|---|
|**Requester / Department**|Documents need, business justification, specifications, budget code, timing, and<br>confirms receipt/acceptance.|
|**Budget owner /**<br>**Department manager**|Confirms necessity, budget availability, and cost-center ownership.|
|**Procurement officer /**<br>**Buyer**|Plans sourcing, supplier invitations, RFQ/RFP administration, quotation comparison,<br>negotiation records, and recommendation.|
|**Evaluation team**|Evaluates bids against pre-approved criteria; members sign confidentiality and<br>conflict-of-interest declarations; individual and consolidated scores and rationale are<br>recorded.|
|**Finance**|Validates budget, tax/accounting treatment, invoice completeness, payment<br>controls, and reconciliation; does not alter evaluation results.|
|**Authorized approver(s)**|Approve sourcing method, award, purchase order, contract, variation, and<br>exceptions within configurable delegation-of-authority thresholds.|
|**Receiver / Technical owner**|Records delivery, inspection, service acceptance, defects, and evidence;<br>independent of supplier where practicable.|
|**Managing Director /**<br>**Executive**|Receives escalated/high-value/strategic approvals according to policy and reviews<br>dashboards/exceptions.|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **21** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

|**Role**|**Responsibilities and controls**|
|---|---|
|**System administrator**|Configures roles and thresholds but must not silently alter procurement records or<br>approve transactions by virtue of technical administration.|



#### **7.4 End-to-end lifecycle** 



<!-- Start of picture text -->
PLAN AND NEED SOURCE AND AWARD<br>SS SS<br>1 2 3 4 5 6<br>Procurement Purchase Budget & need Sourcing Supplier<br>plan requisition validation strategy solicitation Evaluation<br>a<br>i<br>SOURCE AND AWARD CONTRACT DELIVER AND PAY close<br>SS<br>1<br>7 8 9 10) 11 12<br>Award Po/ Delivery & Invoice & Payment Closeout &<br>‘approval contract acceptance 3-way match handoff supplier review<br>Configurable policy matrix drives method, approvals, thresholds and exceptions (nothing hard-coded)<br><!-- End of picture text -->

_Figure 7. Procurement lifecycle in twelve controlled steps, grouped by phase._ 

|**#**|**Stage**|**Key requirements**|
|---|---|---|
|1|Procurement plan|Annual/rolling plan, category, estimated value, need date, budget/cost center,<br>owner, sourcing strategy, and contract duration.|
|2|Purchase requisition|Requester submits specifications, quantity, purpose, delivery location, timing,<br>budget, project link, and supporting documents.|
|3|Budget<br>and<br>need<br>validation|Budget owner and Finance confirm availability; over-budget requests are blocked<br>or escalated under an authorized exception.|
|||Select method through configurable policy based on value, risk, urgency, market|
|4|Sourcing strategy|availability, contract type, and applicable legal/financing regime. Record rationale<br>for restricted/direct sourcing.|
|5|Supplier discovery and<br>solicitation|Supplier qualification; RFQ/RFP/tender issuance; invitation/publication channel<br>where required; controlled clarifications; deadlines; secure, timestamped bid<br>receipt and opening.|
|6|Evaluation|Administrative eligibility/responsiveness, technical compliance, commercial<br>evaluation, and optional weighted scoring using criteria published before bid<br>receipt. Preserve scores, comments, recommendation, and evaluator declarations.|
|7|Award recommendation<br>and approval|Evaluation report, due diligence, conflict checks, budget confirmation, approval<br>under delegated authority, and unsuccessful-bidder notifications where required.|
|8|Purchase order / contract|Generate numbered PO/contract from approved award; capture scope, quantities,<br>price, taxes, currency, delivery milestones, warranties, SLA, payment terms,<br>securities if applicable, and signed documents.|
|9|Delivery and acceptance|Goods receipt, inspection, partial delivery, rejection/defect, return, service<br>completion certificate, and corrective action. Authorized receiver records<br>acceptance.|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **22** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



<!-- Start of picture text -->
# Stage Key requirements<br>Compare PO/contract, receipt/acceptance, and supplier invoice; support partial<br>Invoice and three-way<br>10 receipts, milestones, tolerances, tax validation, discrepancy holds, and approved<br>match<br>exceptions.<br>Approved invoice enters Finance/AP queue; record payment status/reference and<br>11 Payment handoff reconciliation. Bank transfer initiation requires a separately approved integration<br>and controls.<br>Contract closeout and  Verify deliverables, warranties, final payments, claims, asset/inventory handoff,<br>12<br>supplier review performance, lessons learned, and archive/retention.<br><!-- End of picture text -->



<!-- Start of picture text -->
SEGREGATION OF DUTIES<br>| receiveThe sameandpersonauthorizeshouldpaymentnot request,for oneapprove,purchase<br>PO/ contract serviceGoods acceptancereceipt/ Supplierier  invoiceinvei<br>MATCH<br>‘Approved invoice enters the<br>Finance<br>/ AP queue<br>Match engine<br>partial receipts - milestones<br>Tolerances "tax validation DISCREPANCY<br>Invoice hold; approved exception<br>recorded and audited<br><!-- End of picture text -->

_Figure 8. Invoice and three-way match: receipts, orders and invoices are reconciled before payment handoff._ 

#### **7.5 Sourcing methods and policy matrix** 

Support configurable methods such as competitive RFQ (multiple quotations), formal RFP/tender, restricted/invited competition, framework/blanket PO, approved catalog purchase, and justified single-source/emergency purchase. Method names and availability are configured by procurement regime. 

Do not hard-code public procurement monetary thresholds, bid periods, publication rules, or award methods. Maintain effective-dated procurement policy profiles with currency, threshold bands, minimum supplier count, documents, approvals, exceptions, and legal source references. Policy changes require authorized configuration, rationale, effective date, and audit trail. 

For single-source, emergency, or non-competitive methods, require justification, evidence, risk assessment, approval, and post-award review as applicable. Flag repeated purchases or apparent splitting intended to evade thresholds. 

#### **7.6 Supplier master and due diligence** 

**Supplier profile:** legal/trading name, registration/tax identifiers where appropriate, contact details, address, ownership declarations where required, categories, certifications, insurance, references, bank details in restricted fields, and status. 

**Supplier status:** Draft, Under review, Approved, Suspended, Blocked, Archived. Onboarding and bank-detail changes require verification and audit; bank changes trigger independent confirmation and enhanced approval. 

Due diligence checklists may cover registration, tax compliance, sanctions/debarment where applicable, conflicts, capacity, references, quality, cybersecurity/privacy, labor/environmental risk, and beneficial ownership. Configure requirements by type, value, risk, and law. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **23** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

#### **7.7 Contract register, amendments, and performance** 

Maintain contract owner, supplier, scope, value/ceiling, currency, dates, renewal/notice windows, milestones, obligations, deliverables, warranty, SLA/KPI, and linked requisition/award/PO records. 

Amendments require reason and impact on scope/value/time, budget confirmation, review, and approval before execution. Preserve versions; never overwrite the signed original. 

**Alerts:** expiry/renewal, notice deadlines, delivery/milestone slippage, performance-security or insurance expiry, SLA breaches, and contract value utilization. 

#### **7.8 Records, controls, and reporting** 

Immutable audit trail for requisition edits, method selection, invitations, bid uploads/opening, clarifications, evaluation/scoring, conflict declarations, approvals, award, PO/contract changes, receipt, invoice match, exceptions, and closeout. 

Supplier bids and pricing remain confidential until disclosure is authorized. Use restricted access, secure uploads, timestamps, integrity checks, and auditable bid-opening events. 

Retention schedule is configurable by record class, law, financing agreement, dispute, and legal hold. Disposal is authorized and logged; no automatic deletion during hold. 

**Dashboards:** spend by department/category/supplier/project; plan vs actual; requisition aging; cycle time; competitive vs direct sourcing; savings/value analysis methodology; supplier performance; contract renewals/exposure; open orders; defects; invoice holds; exceptions; audit findings. 

#### **7.9 Proposed procurement entities (logical only)** 

|procurement_policy_profiles|procurement_method_rules|procurement_plans|
|---|---|---|
|procurement_plan_lines|purchase_requisitions|requisition_lines|
|sourcing_events|sourcing_event_suppliers|supplier_submissions|
|submission_documents|evaluation_criteria|evaluation_members|
|conflict_declarations|evaluation_scores|award_recommendations|
|purchase_orders|purchase_order_lines|goods_receipts|
|service_acceptances|inspection_records|supplier_invoice references /<br>match_results|
|contract_register|contract_versions|contract_amendments|
|supplier_performance_reviews|supplier_due_diligence|procurement_exceptions|
|procurement_audit_events|||



Before migrations, inspect and reconcile current COPS schema and existing document, approval, notification, lookup, audit, and Finance services. Reuse existing entities and avoid duplicate supplier, approval, file, or invoice masters. 

#### **7.10 Procurement acceptance criteria** 

- A requisition cannot proceed without mandatory data, budget/cost center, and required approvals; over-budget requests are blocked or explicitly escalated. 

- Sourcing method comes from an effective-dated policy matrix; exceptions require rationale, evidence, and approval. 

- Evaluation criteria are locked/versioned before opening; conflicts are declared; scoring and award recommendation are reproducible. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **24** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

- Segregation prevents one person from requesting, approving, receiving, and authorizing payment for the same purchase where required; exceptions are controlled and logged. 

- PO/contract is generated only after award approval; amendment is versioned and approved before effective date. 

- Receipt/acceptance and invoice matching support partial deliveries and discrepancy holds; payment handoff requires authorized approval. 

- Supplier bank details, bids, contracts, and commercial data are access-restricted; sensitive view/download/change is audited. 

- Reports and exports respect organization/department scope and permissions; tests cover crossorganization and privilege escalation. 

#### **7.11 Legal and international reference framework** 

Rwanda reference instruments for legal review: Law No. 031/2022 of 21 November 2022 governing public procurement; Ministerial Order No. 001/23/10/TC of 10 October 2023 establishing regulations governing public procurement; and applicable RPPA guidelines, circulars, standard bidding documents, thresholds, and amendments. Counsel must verify currently effective consolidated instruments before configuring a regulated profile. 

**International benchmarks:** UNCITRAL Model Law on Public Procurement (transparent, competitive, objective, accountable processes); OECD Recommendation on Public Procurement (strategic, transparent, integrity-based, efficient, risk-managed procurement); ISO 20400:2017 Sustainable Procurement—Guidance (voluntary sustainability guidance). These are design benchmarks, not automatically binding law. 

**Reference sources:** <https://www.rppa.gov.rw/>; <https://www.iso.org/standard/63026.html>; Rwanda Law No. 031/2022 and Ministerial Order No. 001/23/10/TC. Legal applicability depends on entity status and transaction context. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **25** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **8. Architecture and implementation standards** 

#### **8.1 Architecture decision** 

Retain a modular monolith. Keep one Laravel application and relational database initially, but organize new business logic by domain. Do not start a rewrite or separate microservices without a documented scaling or ownership need 



<!-- Start of picture text -->
PRESENTATION<br>Client portal<br>COPS Web (PWA) - React 19 - TypeScript - Mantine 9 - TanStack Query (external guard)<br>APIREST AND API SECURITYv1 - Laravel 13 - Sanctum - Policies - Data scopes a) enforcedPermissionsserver-side<br>te &ProjectsBilling PayrollHR & (::3]a Meeti& Eve n gt s Procurement& Contracts<br>SHARED CORE SERVICES<br>Approval engine Employee master Clients Controlled files<br>Audit log Notifier Lookups Reports<br>(=) MysQl 8.4 e Redis (queues) e Reverb (real-time) Ce) S3 private storage 9g ClamAV scan<br><!-- End of picture text -->

_Figure 9. Proposed modular architecture: the existing stack is retained and four business domains share controlled core services._ 

#### **8.2 Backend organization** 

Follow existing repository conventions (app/Http/Controllers/Api/V1, Models, Services) and group new code by domain, for example Services/Projects, Services/Hr, Services/Meetings, Services/Billing, Services/Procurement. Prefer thin controllers, Form Requests, Policies, Actions/Services, API Resources, events/listeners, and queued jobs. 

#### **8.3 API conventions** 

- Use existing REST v1 conventions and routes in routes/api.php. 

- Every route documents its permission and data scope. 

- Document full request/response schemas in docs/openapi.yaml. 

- Use Form Requests for validation and API Resources for responses. 

- Implement pagination, filtering, sorting, search, consistent errors, transaction-safe actions, and idempotency for repeat-sensitive operations. 

- Preserve Sanctum cookie session and frontend CSRF conventions. 

#### **8.4 Database design rules** 

- All tenant business records include organization_id and proper organization scope. 

- Use foreign keys, indexes, relational lookups, and normalized structures. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **26** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

- Avoid enums and JSON columns for core data/rules. 

- Use effective-dated history for employment, rates, legal parameters, payroll schemes, and policies. 

- Use DECIMAL(15,2) for monetary values, currency code per financial document, and centralized rounding. 

- Use UTC storage and timezone-aware presentation. 

- Migrations reversible; seeders idempotent; no destructive production migration without backup and review. 

#### **8.5 Infrastructure and performance** 

|**Area**|**Recommendation**|
|---|---|
|**Queue/cache/**<br>**WebSockets**|Introduce Redis for queues, cache, and Reverb scaling when bulk invitations, payroll,<br>and long-running jobs are added.|
|**Long jobs**|Payroll calculations, PDF generation, bulk e-mail, large exports run asynchronously;<br>expose job status/progress in UI.|
|**Real-time**|Reuse Reverb/Echo for timer sync, task changes, meeting updates, and notifications.|
|**Search**|Start with MySQL FULLTEXT; consider Meilisearch only after measured need.|
|**Calendar / UI libraries**|Use dnd-kit for Kanban; lazy-load calendar library (e.g. FullCalendar); Gantt<br>optional/lazy; Mantine Charts.|
|**PDF**|Dompdf for invoices, payslips, offers, and minutes; templates translated in four<br>languages.|
|**Storage**|Private S3-compatible storage in production, signed expiring URLs; ClamAV scanning.|
|**Deployment**|Provide Docker development setup and deployment/runbook aligned with current<br>roadmap.|



#### **8.6 Security requirements** 

- Deliver two-factor authentication before HR/payroll go-live; prioritize payroll, finance, and director roles. 

- Apply rate limiting and secure session settings to external portal. 

- Validate file content/type/size and scan before access. 

- Encrypt sensitive HR fields at rest and use TLS in transit. 

- Test IDOR, cross-tenant, cross-department, cross-client, and privilege escalation cases. 

- Review retention, backups, recovery, access logging, secrets, and incident response. 

#### **8.7 CI and quality tooling** 

- GitHub Actions: PHPUnit/Pest, Larastan or PHPStan, Laravel Pint, composer audit, npm audit, typecheck, Vitest, and build:check. 

- Add Playwright journeys for critical business processes. 

- Branch convention: feature/short-name. Each pull request states what changed, why, test evidence, migrations, and screenshots. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **27** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **9. Phased delivery roadmap** 

|**Phase**|**Scope**|**Exit criterion**|
|---|---|---|
|**0. Shared foundations**|Approval engine; department scope; employee<br>master; clients/external identities; antivirus;<br>CI/security foundations, configurable rules and<br>policy engine|Approval and access-scope tests<br>pass; shared services documented|
|**1. Projects core**|Projects, departments/roles, tasks, comments,<br>attachments, workflow, Kanban|Project runs end-to-end without<br>time or billing|
|**2. Time & billing**|Persistent timer, timesheets, rate cards, draft<br>invoice, approvals, payments, PDF|Delivered<br>project<br>generates<br>reproducible invoice from approved<br>time|
|**3. Client portal**|External identities, client-scoped views, visibility<br>flags, uploads|Cross-client access and upload<br>scanning tests pass|
|**4. HR core**|Employees, categories, effective history, career<br>path, recruitment, attendance|Recruitment-to-active-employee<br>journey works|
|**5. Leave & trips**|Legal parameters, policy engine, balances,<br>approvals, travel/expenses|HR/legal-approved examples pass;<br>no policy below statutory floor|
|**6. Payroll**|Components, schemes, brackets, runs, payslips,<br>exports, declarations|Two-month parallel run matches<br>accountant-approved payroll to<br>franc|
|**7. Meetings & events**|Scheduling, invitations, agendas, attendance,<br>minutes, review, publication, resolutions|Invitation-to-published-minutes<br>E2E journey passes|
|**8. Procurement &**<br>**contracts (proposed)**|Policy profiles, requisitions, sourcing events,<br>evaluation, awards, PO/contract, receipts, three-<br>way match, supplier master, contract register|Requisition-to-payment-handoff<br>journey passes; segregation-of-<br>duties and confidentiality tests<br>pass|





<!-- Start of picture text -->
Relative delivery sequence (not calendar time)<br>1<br>© Shared foundations [ preseo |<br>p<br>1 Projects core<br>I<br>2 Time & billing !<br>1<br>3 Client portal 1I [pnese> |<br>1<br>I<br>5 Leave & trips 1 1 [ pnases |<br>1<br>I<br>7 Meetings & events =<br>1<br>8 Procurement (proposed) H<br>1<br>I Main track J May run in parallel (Phase 7 after Phase 0; Phase @ reuses Phase 2 finance)<br><!-- End of picture text -->

_Figure 10. Phased delivery roadmap (relative sequence). Meetings and Procurement may run in parallel after their_ 

_prerequisites are complete._ 

Phase 7 is independent of HR phases 4–6 and may run in parallel with a second developer after Phase 0. Do not start parallel implementation before shared approval, scope, identity, and document foundations stabilize. Phase 8 (proposed) reuses the Finance and approval capabilities delivered in Phases 0 and 2 and may run in parallel with Phases 3 to 6 once those are stable. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **28** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **10. Quality gates and definition of done** 

**1.** Server permissions tested for allowed, denied, cross-tenant, cross-department, and cross-client scenarios. 

**2.** No ENUM or JSON columns for core/configurable business structures; lookups and seeders supplied. 

**3.** Sensitive reads, exports, approvals, transitions, and financial/HR actions audited. 

**4.** All UI/server strings translated en, fr, rw, sw. 

**5.** Money, time, dates, and timezone handled through shared services. 

**6.** Feature/unit tests include calculation logic: payroll, leave accrual, timer, invoice rounding, tax, three-way procurement match, and workflow transitions. 

**7.** OpenAPI updated; migrations reversible; seeders idempotent; regression checklist updated at docs/REGRESSION-CHECKLIST.md. 

**8.** Screens work at 360 px, are keyboard accessible, lazy-loaded, and meet bundle budget; PR includes phone and desktop screenshots. 

**9.** Payroll/leave/invoice calculations include golden-file tests from worked examples signed off by accountant or HR/legal reviewer. 

**10.** Each phase includes documentation, deployment instructions, changelog, known issues, demonstration, and approval. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **29** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **11. Product-owner decisions and go-live checklist** 

Earlier drafts listed 17 questions. On review, most are business rules that belong in the configurable rules and policy engine (Section 3.7), not in decisions that block development. Only items that change scope, hosting, legal validity or integrations remain as decisions. 

#### **11.1 Blocking decisions (needed before the affected work starts)** 

|**#**|**Decision / confirmation required**|**Owner / timing**|
|---|---|---|
|1|Who validates statutory leave parameters, maternity/paternity ministerial-<br>order values, and international/data-protection review?|HR + legal; before Leave go-<br>live|
|2|Who supplies and signs current PAYE, RSSB, health/maternity/occupational<br>schemes, brackets, and category treatment?|Accountant + Finance; before<br>payroll build/seed|
|3|Must invoices integrate with RRA EBM/e-invoicing? Is VAT applicable by<br>service/category?|Finance + tax adviser; before<br>billing go-live|
|4|Which biometric attendance hardware/import formats are in scope?|HR + IT|
|5|May HR/payroll data be hosted in cloud private storage or must it remain on-<br>premises?|Management + data protection<br>officer|
|6|How many developers are available; may Meetings be developed in parallel<br>after Phase 0?|Product owner / technical lead|
|7|Which calendar/email providers and SMS gateway are approved for<br>integration?|IT / management|
|8|Is the organization a covered public entity, or does any donor or financing<br>agreement impose procurement rules? Which regulated-procurement profile,<br>if any, must be configured?|Legal + Finance; before<br>Procurement build|



#### **11.2 Business rules seeded with defaults and configured by administrators** 

These items do not block development. The system ships with the conservative defaults below. The named role configures the values and, where indicated, signs them off before go-live. 

|**Setting**|**Seeded default**|**Configured / signed off**<br>**by**|
|---|---|---|
|**Billing models**|Time and materials, fixed price and milestone all<br>supported; enabled per project|Finance|
|**Client portal visibility**|Progress only; invoices and uploads off until enabled per<br>client or project; comments internal by default|Product<br>owner<br>+<br>commercial lead|
|**Currency**|Base currency RWF; additional currencies and<br>exchange-rate source off until enabled|Finance|
|**Retention periods**|Seeded schedule per record class flagged Unverified; no<br>automatic deletion until signed off|Legal / records officer<br>(sign-off)|
|**Approval authority for**<br>**budget changes,**<br>**amendments, credit**<br>**notes, write-offs, closure**<br>**exceptions**|Two-level approval with managing director above the<br>threshold; every request needs approval until<br>thresholds are set|Management + Finance|
|**Approval thresholds and**<br>**escalation times (leave,**<br>**trips, timesheets,**<br>**invoices, payroll,**<br>**publication)**|Line manager, then department head; escalation after a<br>set delay; no self-approval|Management / HR /<br>Finance|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **30** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

|**Setting**|**Seeded default**|**Configured / signed off**<br>**by**|
|---|---|---|
|**Employee categories and**<br>**contract types**|Intern, Probation, Contracted, Casual, Volunteer;<br>extensible lookups|HR + legal (sign-off)|
|**Client portal security**|MFA required for client users; invitation expiry, file<br>types and size limits set by IT|IT + product owner|
|**Procurement thresholds,**<br>**methods, quotation**<br>**counts, approval chains**|Every purchase requires explicit approval until the<br>thresholds of the selected policy profile are configured|Finance + management|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **31** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **Appendix A — Proposed data entities** 

Logical entities only. The final schema must be reconciled with the existing COPS tables before any migration is written. 

#### **A.1 Shared foundation** 

- approval_flows, approval_steps, approval_requests, approval_actions 

- user_department_assignments and scope assignments 

- hr_employees as canonical employee master; employment_records and user link 

- clients, client_contacts, external client identities/guard 

- Existing comments, controlled files, audit, notifier, lookup, reporting services 

- Rules engine: rule_sets, rule_versions, rule_conditions, rule_outcomes, rule_activations, legal_parameters. 

#### **A.2 Projects and billing** 

|clients|client_contacts|projects|
|---|---|---|
|project_departments|project_members|project_statuses|
|project_status_transitions|milestones|tasks|
|task_assignees|task_dependencies|task_statuses|
|time_entries|timesheets|rate_cards|
|rate_card_lines|contracts|invoices|
|invoice_lines|invoice_payments|credit_notes|



#### **A.3 HR** 

|hr_employees|employment_records|positions|
|---|---|---|
|grades|employee_categories|requisitions|
|applicants|application_stages|interviews|
|scorecards|offers|shifts|
|attendance_records|leave_types|leave_policies|
|legal_leave_rules|leave_balances|leave_requests|
|trip_requests|trip_advances|trip_expenses|
|pay_components|statutory_schemes|tax_brackets|
|category_scheme_assignments|payroll_runs|payslips|
|payslip_lines|performance_reviews||



#### **A.4 Meetings and events** 

|meeting_types|meetings|meeting_recurrences|
|---|---|---|
|rooms|room_bookings|agenda_items|
|invitations|attendances|minutes|
|minute_versions|minute_comments|resolutions|
|action_items|distributions|events|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **32** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

event_registrations event_attendance 

event_expenses 

#### **A.5 Procurement & contracts** 

|procurement_policy_profiles|procurement_method_rules|procurement_plans|
|---|---|---|
|purchase_requisitions|sourcing_events|supplier_submissions|
|evaluation_criteria|evaluation_scores|award_recommendations|
|purchase_orders|goods_receipts|service_acceptances|
|supplier_invoice references|match_results|contract_register|
|contract_versions|contract_amendments|supplier_due_diligence|
|supplier_performance_reviews|procurement_exceptions||



Final schema must be reconciled with existing tables and naming conventions. Avoid duplicating existing entities; use migrations only after ERD and relationship review. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **33** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 

### **Appendix B — Design decisions adopted in this baseline** 

The table summarizes the principal improvements adopted from the product-owner supplement when the guide was consolidated. 

|**Design decision**|**Benefit**|
|---|---|
|**Generic approval engine with ordered**<br>**steps, delegation, escalation, no self-**<br>**approval**|Replaces separate ad hoc approvals across projects, HR, payroll, and<br>minutes with reusable infrastructure.|
|**Department scope helper and explicit**<br>**own/team/department/organization**<br>**scope**|Makes director visibility and departmental boundaries consistent<br>across every endpoint.|
|**Single hr_employees master; user**<br>**account optional**|Supports interns, casual workers, volunteers, and employees without<br>login credentials.|
|**Separate external client guard and**<br>**client-scoped global scope**|Strengthens portal boundary and explicit 404 cross-client acceptance<br>test.|
|**Exact project lifecycle and hard**<br>**transition gates**|Clarifies signed-contract activation, task acceptance, delivery, invoice,<br>disputed items, and closure.|
|**Persistent server-authoritative timer,**<br>**idle warning, auto-stop, overlapping-**<br>**entry rejection**|Adds practical professional timekeeping and recoverability beyond<br>basic stopwatch.|
|**Immutable approved/invoiced time**<br>**entries and adjustment records**|Protects audit integrity and prevents retrospective billing<br>manipulation.|
|**Invoice draft triggered on Delivered;**<br>**thresholds route to MD; invoices never**<br>**deleted**|Clarifies separation of delivery, finance review, issuance, payment,<br>and credit note.|
|**Effective-dated employment history**<br>**and legal/payroll rule parameters**|Keeps historical payslips reproducible when employee terms or rates<br>change.|
|**Leave legal baseline with explicit**<br>**uncertainty and counsel validation**|Preserves useful Rwanda law analysis without hard-coding conflicting<br>maternity/paternity day counts.|
|**Payroll rule schema, source**<br>**provenance, second-person rate**<br>**approval, dry-run and parallel-run**<br>**gates**|Adds control over legally sensitive calculations and measurable<br>production readiness.|
|**ICS, RSVP, room conflict detection,**<br>**immutable published minutes,**<br>**resolution-to-task conversion**|Completes meeting lifecycle and connects decisions to execution.|
|**ClamAV, signed URLs, private storage,**<br>**2FA prioritization, CI tooling**|Adds concrete production security and delivery quality requirements.|
|**Configurable rules and policy engine**|Replaces most blocking questions with versioned, approved, effective-<br>dated configuration; only scope, hosting, legal and integration items<br>remain decisions.|



COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **34** of 35 

**COPS** |  Company Operations Platform 

Requirements & Developer Guide 



### **Appendix C — Legal and regulatory validation caveat** 

This guide is a product/technical requirements document, not legal or tax advice. All statutory leave, payroll rates, tax schemes, deadlines, retention rules, data protection obligations, and RRA e-invoicing requirements must be verified against current official sources and approved by qualified Rwandan legal, HR, and accounting professionals before production activation. Unverified parameters must be clearly flagged and must not be silently treated as authoritative. 

COPS Enhancement  •  Version 1.2  •  Draft for review  •  2 November 2026 

Page **35** of 35 

