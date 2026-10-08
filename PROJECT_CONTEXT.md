# PROJECT_CONTEXT.md

## 1. Project Overview

- **Project Name:** Aureus ERP (`aureuserp/aureuserp`)
- **Primary Client:** Truck It In (Pvt) Ltd
- **Architecture Type:** Modular Plugin Monorepo on Laravel + Filament Admin Panels
- **Application Purpose:** Open-source ERP platform tailored for SMEs and enterprise operations, covering Financial Accounting & General Ledger, HR & Geofenced Attendance, Approvals & Workflows, Recruitment, Time Off, Inventory, Manufacturing, Sales, Purchases, and P2P/Drive document exchange.

---

## 2. Architecture

```
Client Browser (Filament 5 / Livewire 4)
                  ↓
          HTTP / Middleware
 (Company Scope, SetLocale, Shield Gates, Permission Scope)
                  ↓
       Modular Monorepo Plugins (`plugins/webkul/*`)
   ├── Accounts / Accounting (GL, Moves, Reconciliation, Bank Import)
   ├── Support (Centralized Approval Engine, Currencies, Companies)
   ├── Security (Bouncer, Permission Scope, Spatie Shield Roles)
   ├── Employees / Time-Off / Recruitments (HR, Attendance, Hierarchy)
   └── Operations (Products, Inventories, Sales, Purchases, Manufacturing)
                  ↓
             Data Layer
   MySQL 8.4 (Company Scoping, Foreign Keys, Strict Balances)
                  ↓
   Asynchronous Background Jobs / Queues / Cron
  (Drive Ingestion, Notifications, WebRTC Signatures, Cleanup)
```

### Key Architectural Tenets
1. **Plugin-Driven Structure:** Business capabilities live inside `plugins/webkul/{module}` as self-contained packages managed via `wikimedia/composer-merge-plugin` and `plugin-manager`.
2. **Unified Core Ledger:** All accounting moves (Invoices, Vendor Bills, Credit Notes, Refunds, Journal Entries) converge into a single model in `accounts`: [Move](file:///c:/Intern/Handover/Erp/plugins/webkul/accounts/src/Models/Move.php) (`accounts_account_moves`).
3. **Dual-Plugin Accounting Boundary:**
   - **`accounts`**: Low-level double-entry bookkeeping engine (`Move`, `MoveLine`, `Account`, `Journal`, `Payment`, `Tax`).
   - **`accounting`**: Advanced financial operational layer (Bank statement multi-parser imports, FS tag mapping, Financial Reports, Google Drive automated PDF ingestion/export, and WebRTC/P2P document exchange).
4. **Shared Universal Approval Engine:** No domain module implements custom approval tables. All approvals (HR Claims, Sensitive Changes, Time Off, Financial Approvals) plug into `support`'s [ApprovalEngine](file:///c:/Intern/Handover/Erp/plugins/webkul/support/src/Services/ApprovalEngine.php) using morphable `ApprovalRequest`, `ApprovalStep`, and string `request_type`.
5. **Strict Company Scoping:** Multi-tenant company boundary enforced at record level via `company_id` and [UserPermissionScope](file:///c:/Intern/Handover/Erp/plugins/webkul/security/src/Models/Scopes/UserPermissionScope.php) / [HasPermissionScope](file:///c:/Intern/Handover/Erp/plugins/webkul/security/src/Traits/HasPermissionScope.php) (`GLOBAL`, `GROUP`, `INDIVIDUAL`).

---

## 3. Technology Stack

- **PHP:** `^8.3`
- **Framework:** Laravel `^13.0`
- **Admin UI / Components:** Filament `^5.0`, Livewire `^4.0`, Tailwind CSS
- **Database:** MySQL 8.4 (`InnoDB`, strict mode)
- **Role & Access Control:** Spatie Laravel Permission / `bezhansalleh/filament-shield` (`^4.0`)
- **Queue & Cache:** Database-backed (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`)
- **Document Generation & Office Formats:** Barryvdh DomPDF (`^3.1`), Maatwebsite Excel (`^3.1`)
- **External API / Hardware / Mobile:** Google API Client (`^2.18`), NativePHP Mobile (`^3.3`), WebRTC (direct browser-to-browser data channel)
- **Code Quality & Testing:** Pest PHP (`^4.4`), Laravel Boost (`^2.1`), Laravel Pint (`^1.27`)

---

## 4. Module Inventory

| Module / Plugin | Status | Core Responsibility | Primary Models / Services |
|---|---|---|---|
| **`accounts`** | `COMPLETE` | Canonical double-entry general ledger, payment execution, tax logic | `Move`, `MoveLine`, `Account`, `Journal`, `Payment`, `TaxManager` |
| **`accounting`** | `MOSTLY COMPLETE` | Bank imports, transaction mapping, FS tags, reporting engine, P2P & Drive sync | `BankStatementImportService`, `BankMappingService`, `ReportCalculationEngine`, `DocumentExchangeService` |
| **`support`** | `COMPLETE` | Central approval engine, currency conversions, multi-company master | `ApprovalEngine`, `ApprovalRequest`, `ApprovalStep`, `Company`, `Currency` |
| **`security`** | `COMPLETE` | Spatie Shield permissions, Bouncer, UserPermissionScope | `User`, `Role`, `Permission`, `UserPermissionScope`, `Bouncer` |
| **`employees`** | `COMPLETE` | HR master, reporting hierarchy, claims, sensitive change audit, geofenced attendance | `Employee`, `Department`, `EmployeeRequest`, `AttendanceRecord`, `HrHierarchyService` |
| **`time-off`** | `COMPLETE` | Leave allocations, accruals, leave requests routed via ApprovalEngine | `Leave`, `LeaveAllocation`, `LeaveType`, `LeaveApprovalService` |
| **`recruitments`** | `COMPLETE` | Candidate pipeline, interview stages, job offers, employee conversion | `Applicant`, `Candidate`, `JobPosition`, `RecruitmentPlugin` |
| **`invoices`** | `COMPLETE` | Dedicated presentation layer for customer/vendor invoicing views | Clusters `Customers`, `Vendors`, `InvoiceResource`, `BillResource` |
| **`products`** | `COMPLETE` | Catalog, variants, prices, UOM, supplier associations | `Product`, `ProductCategory`, `Packaging`, `PriceRule` |
| **`inventories`** | `PARTIALLY COMPLETE` | Warehouses, stock moves, locations (large legacy tests gap) | `StockMove`, `Warehouse`, `Location`, `InventoryManager` |
| **`sales`** / **`purchases`** | `MOSTLY COMPLETE` | Quotes, SOs, RFQs, POs (converts to `Move` upon confirmation) | `SaleOrder`, `PurchaseOrder`, `SaleManager` |
| **`partners`** / **`contacts`** | `COMPLETE` | Customer and vendor party profiles, bank details, tax numbers | `Partner`, `BankAccount`, `Industry`, `PartnerPlugin` |
| **`chatter`** | `COMPLETE` | Universal audit trail and discussion feed trait on business models | `ChatterMessage`, `ChatterFollower`, `HasChatter`, `HasLogActivity` |
| **`fields`** | `COMPLETE` | Dynamic custom field attachments without schema alterations | `CustomField`, `HasCustomFields` |
| **`manufacturing`** | `PARTIALLY COMPLETE` | BOM, work centers, work orders (pre-existing test failures) | `MrpProduction`, `WorkCenter`, `ManufacturingPlugin` |

---

## 5. Important Files

- [composer.json](file:///c:/Intern/Handover/Erp/composer.json): Source of truth for all dependencies, PHP 8.3/Laravel 13 constraints, and merge-plugin configuration.
- [routes/console.php](file:///c:/Intern/Handover/Erp/routes/console.php): Definitive scheduler schedule configuration (Drive sync, HR geofence housekeeping).
- [AdminPanelProvider.php](file:///c:/Intern/Handover/Erp/app/Providers/Filament/AdminPanelProvider.php): Global Filament admin panel setup, registered widgets, Shield plugin registration.
- [AppServiceProvider.php](file:///c:/Intern/Handover/Erp/app/Providers/AppServiceProvider.php): Core application provider, event listener registration, NativePHP components.
- [ApprovalEngine.php](file:///c:/Intern/Handover/Erp/plugins/webkul/support/src/Services/ApprovalEngine.php): The central workflow authority for all multi-tier approval routing.
- [AccountingPermissions.php](file:///c:/Intern/Handover/Erp/plugins/webkul/accounting/src/Support/AccountingPermissions.php): Complete curated permission map and role configurations for the entire accounting domain.
- [HrHierarchyService.php](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Services/HrHierarchyService.php): Company-scoped hierarchical traversal engine determining manager visibility and approvals.
- [Move.php](file:///c:/Intern/Handover/Erp/plugins/webkul/accounts/src/Models/Move.php): Master ledger entry model (Invoices, Bills, Journal Entries).
- [BankStatementImportService.php](file:///c:/Intern/Handover/Erp/plugins/webkul/accounting/src/Services/Bank/BankStatementImportService.php): Multi-format parser, deduplicator, and ledger feeder for bank statements.
- [BankMappingService.php](file:///c:/Intern/Handover/Erp/plugins/webkul/accounting/src/Services/Bank/BankMappingService.php): Bank transaction mapping engine with FS Tag and rule evaluation.
- [UserPermissionScope.php](file:///c:/Intern/Handover/Erp/plugins/webkul/security/src/Models/Scopes/UserPermissionScope.php): Eloquent global query scope enforcing `GLOBAL`/`GROUP`/`INDIVIDUAL` record visibility.
- [HANDOVER.md](file:///c:/Intern/Handover/Erp/HANDOVER.md): Handover summary from prior delivery session detailing architectural decisions and caveats.
- [APP_TESTING_STATUS.md](file:///c:/Intern/Handover/Erp/APP_TESTING_STATUS.md) & [APP_TESTING_ERRORS.md](file:///c:/Intern/Handover/Erp/APP_TESTING_ERRORS.md): Detailed QA audit records and retest verification history.

---

## 6. Database & Core Business Entities

### Key Schema Areas
1. **Ledger & Accounting (`accounts_*`):**
   - `accounts_account_moves`: The ledger backbone. `move_type` distinguishes invoices (`out_invoice`), bills (`in_invoice`), and entries (`entry`). `state` cycles strictly: `draft` → `posted` → `cancel`.
   - `accounts_account_move_lines`: Debit/credit balanced transaction lines attached to an account and company.
   - `accounts_accounts`: Chart of Accounts hierarchy with code uniqueness per company.
   - `accounts_period_locks`: Hard period locks preventing backdated posting or modifications.
2. **Operations & Mapping (`accounting_*`):**
   - `accounting_bank_transaction_mappings`: Links bank statement lines to offset accounts, FS tags, and draft/posted journal entries.
   - `accounting_documents`, `accounting_document_versions`, `accounting_document_audits`: Document management storage and integrity hashing.
   - `accounting_report_templates`, `accounting_report_lines`, `accounting_report_columns`: Dynamically computed financial reporting engine.
   - `accounting_webrtc_sessions`, `accounting_peers`, `accounting_outbound_transmissions`: P2P exchange models.
3. **HR & Workflow (`employees_*`, `support_*`):**
   - `support_approval_workflows`, `support_approval_steps`, `support_approval_requests`, `support_approval_decisions`: Centralized workflow engine state.
   - `employees_employees`: Employee master linked to `departments`, `users`, and self-referential `parent_id` (manager).
   - `employees_attendance_records`, `employees_attendance_verifications`: Geofenced GPS-verified attendance records.
4. **Company Isolation:**
   - Every operational table carries `company_id`.
   - No cross-company sharing of accounts, journals, taxes, transactions, or employees.

---

## 7. Major Workflows

### A. Bank Statement Import to General Ledger Posting
```
Bank Statement File (CSV/XLSX)
    ↓
BankStatementImportService (Parses bank format, SHA-256 fingerprint deduplication)
    ↓
BankStatement & BankStatementLine created
    ↓
BankMappingService (Applies BankMappingRules, BusinessRules, and FS Tags)
    ↓
BankTransactionMapping (Manual Review / Approval in Filament UI)
    ↓
BankJournalService (Generates draft Move and MoveLines)
    ↓
Authorized Poster / Controller Review
    ↓
Posted to General Ledger (`accounts_account_moves`)
```

### B. Shared Multi-Tier Approval Workflow
```
Action Triggered (e.g., HR Expense Claim, Leave Request, Sensitive Data Edit)
    ↓
Module Service (`EmployeeRequestService` or `LeaveApprovalService`)
    ↓
ApprovalEngine::submit() (Matches active ApprovalWorkflow by `request_type`, amount, company)
    ↓
ApprovalRequest created (State: `pending`, mapped to initial `ApprovalStep`)
    ↓
Eligible Approver (Resolved via direct user, assigned role, or `HrHierarchyService`)
    ↓
ApprovalEngine::decide() (Accept / Reject with audit trail in `support_approval_decisions`)
    ↓
Transition to next step OR Final Approval:
    → Triggers domain action (e.g., creates Draft Move in accounting or updates employee master)
```

### C. Automated Invoice Google Drive Ingestion & Sync
```
Scheduled Cron (routes/console.php: everyMinute)
    ↓
`accounting:drive:sync-inbound` / DiscoverDriveIngestionsJob
    ↓
DriveIngestionService (Pulls files from linked Google Drive folder)
    ↓
DriveClassificationService (Extracts text from PDF via PdfInvoiceTextExtractor, parses totals & supplier)
    ↓
DriveInvoicePostingService (Matches supplier, generates draft Move in `accounts`)
    ↓
Optional: Outbound Export via InvoiceDriveExportService (DomPDF generation uploaded back to Drive)
```

---

## 8. Integrations

1. **Google Drive API (`google/apiclient`):**
   - Configured in `config/accounting_drive.php`.
   - Handles two-way document synchronization (ingestion of supplier invoices and export of confirmed customer invoices).
2. **WebRTC P2P Transfer:**
   - Configured in `config/webrtc.php` & `config/accounting_peers.php`.
   - In-memory/browser direct file exchange between paired Aureus ERP instances without passing raw payload bytes through intermediary storage.
3. **Mail / SMTP:**
   - Configured in `config/mail.php`.
   - Email notifications for approvals and claim links (in development, fallback to log driver).
4. **NativePHP Mobile:**
   - Components bound in `AppServiceProvider` for hybrid mobile client rendering.

---

## 9. Authorization & Security

1. **Role-Based Access Control:**
   - Implemented via `bezhansalleh/filament-shield` and Spatie Permission.
   - Comprehensive permission definitions maintained in [AccountingPermissions.php](file:///c:/Intern/Handover/Erp/plugins/webkul/accounting/src/Support/AccountingPermissions.php).
   - Segregation of duties enforced:
     - `Accountant`: View-only across ledger, preparing mappings, cannot post or lock periods.
     - `Controller`: Can approve journals, post to GL, and toggle period locks.
     - `Internal Auditor` & `External Auditor`: Strictly read-only audit access across all financial and approval records.
2. **Row-Level Permission Scoping:**
   - Models implementing `HasPermissionScope` trait automatically filter queries based on user's `resource_permission`:
     - `GLOBAL`: Sees all records within their company.
     - `GROUP`: Restricted to user's assigned teams.
     - `INDIVIDUAL`: Restricted strictly to creator/owner.
3. **HR Hierarchy Scoping:**
   - [HrHierarchyService](file:///c:/Intern/Handover/Erp/plugins/webkul/employees/src/Services/HrHierarchyService.php) resolves nested reporting lines dynamically (`parent_id`, department management, team management).
4. **Server-Side Enforcement:**
   - Filament actions execute server-side authorization checks and service-level guards (`halt()` on unauthorized states) rather than relying exclusively on UI visibility flags.

---

## 10. Testing

- **Testing Framework:** Pest PHP + PHPUnit.
- **Test Locations:**
  - Root tests: `tests/Feature/` (system audits, role actions, cross-module integration).
  - Plugin tests: `plugins/webkul/{plugin}/tests/Feature/`.
- **Primary Tested Suites:**
  - `accounting`: Bank workflows, FS tag propagation, multi-currency accounting, period locks, Drive ingestion.
  - `accounts`: Ledger balances, tax compliance, move state machines.
  - `employees`: Claims workflow, onboarding, attendance geofencing, HR permissions.
  - `support`: Approval engine mechanics, strict currency conversion.
- **Known Test Suite Status:**
  - Core accounting and HR suites are solid and well-verified.
  - `inventories` and `manufacturing` plugins contain legacy pre-existing fixture failures that were inherited and outside the current engagement scope.

---

## 11. Completed Work

- End-to-end multi-tier HR workflows (Recruitment, Expense Claims, Sensitive Employee Data Changes) routed through the shared `ApprovalEngine`.
- Geofenced attendance tracking with scheduled shift completion notifications and forgotten shift detection.
- Complete bank statement import pipeline supporting multi-sheet workbooks, duplicate detection, and FS tag mapping.
- Financial reporting engine supporting Balance Sheet, Profit & Loss, Trial Balance, Aged Payables/Receivables, and Direct Cash Flow.
- Segregated financial roles (Finance Operator, AP Officer, AR Officer, Treasury, Reconciliation, Tax, Controller, Auditors).
- Server-side posting guards and period-lock protection (`accounts_period_locks`).
- Google Drive invoice ingestion/export service with duplicate detection and error formatting.
- Fixed 12 core security, company isolation, and self-approval defects documented in `APP_TESTING_STATUS.md`.

---

## 12. In-Progress & Uncommitted Work

At the time of this discovery, the local working tree has 8 modified files and 2 untracked items:
1. **Real-Time Invoice Collaboration Listener:**
   - `app/Listeners/NotifyUsersOnMoveConfirmed.php` (untracked) & `AppServiceProvider.php` (modified): Sends database notifications to company users when an invoice is confirmed/posted. Tested by `tests/Feature/InvoiceRealTimeCollaborationTest.php`.
2. **Google Drive Integration UI Action:**
   - `plugins/webkul/accounting/src/Filament/Actions/OpenInDriveAction.php` (untracked): Adds an "Open in Drive" button on invoice views when synced.
   - `plugins/webkul/accounting/src/Services/Drive/InvoiceDriveExportService.php` (modified): Enhancements to drive export logic.
3. **Invoice Summary Livewire Component Enhancements:**
   - `plugins/webkul/accounts/src/Livewire/InvoiceSummary.php` and its blade view: Displays Google Drive sync status directly in the invoice summary view.

---

## 13. Known Issues & Limitations

1. **Missing TURN Server for WebRTC:**
   - WebRTC signaling relies solely on public STUN servers. Direct transfers may fail across symmetric/strict enterprise corporate NATs without a configured TURN server.
2. **Email SMTP in Dev Mode:**
   - Production SMTP credentials are not configured in local environment; notification links write to the Laravel log.
3. **`AccountingPermissions::TransferDocuments`:**
   - Referenced in intra-instance document transfer documentation but omitted from permissions registration pending business role assignment decisions.
4. **Pre-Existing Inventory/Manufacturing Test Failures:**
   - Inherited legacy test fixture gaps in `inventories` and `manufacturing` plugins remain present in the background.

---

## 14. Risks

1. **Company Isolation Breakage:**
   - Adding new Filament resources or select pickers without explicit `->where('company_id', ...)` or `getEloquentQuery()` scoping risks leaking multi-company data.
2. **Direct Ledger Mutation Risk:**
   - Modifying `Move` or `MoveLine` rows directly via `DB::` or bypassing the `draft → posted` workflow breaks debit/credit balance integrity and auditability.
3. **Approval Bypasses:**
   - Bypassing `ApprovalEngine` by directly modifying status columns on subject models leaves the approval audit trail incomplete and breaks SLA tracking.

---

## 15. Important Conventions & Rules

- **Follow Laravel Boost & Project AGENTS.md Guidelines:** Always check `composer.json` for package versions before using new syntax.
- **Code Style:** Strict types, curly braces for all control blocks, explicit return types on methods, constructor property promotion. Pint formatters must be run on changed PHP (`vendor/bin/pint --dirty`).
- **Never Use `env()` in Application Code:** Always retrieve configuration values via `config(...)`.
- **Database Modesty:** Never introduce destructive migrations or alter posted ledger lines.
- **Company Scoping is Mandatory:** Never remove company scoping simply to satisfy a failing query.

---

## 16. Areas That Should NOT Be Recreated (Avoid Duplication!)

- **DO NOT create a new Approval System:** Always use `Webkul\Support\Services\ApprovalEngine` and `ApprovalRequest`.
- **DO NOT create a separate Invoice/Bill Ledger:** Customer invoices, vendor bills, and journal entries all belong to `Webkul\Account\Models\Move`.
- **DO NOT write custom Bank Statement Parsers from scratch:** Use `Webkul\Accounting\Services\Bank\BankStatementParserRegistry` and extend `AbstractSpreadsheetBankStatementParser`.
- **DO NOT implement redundant HR Hierarchy Queries:** Use `Webkul\Employee\Services\HrHierarchyService`.
- **DO NOT create a custom Activity or Comment feed:** Use the existing `HasChatter` and `HasLogActivity` traits from `Webkul\Chatter`.

---

## 17. Recommended Next Investigation Points

1. **Review and Commit Working Tree Changes:** Verify and finalize the pending real-time invoice notification listener and Google Drive view actions (`git status`).
2. **Execute Full Test Suite Verification:** Run `php artisan test --filter=InvoiceRealTimeCollaborationTest` and relevant accounting/HR feature tests to ensure zero regressions.
3. **Scheduler Daemon Verification:** Verify in deployment environments that `php artisan schedule:work` is actively running to trigger the scheduled Drive and HR jobs in `routes/console.php`.
