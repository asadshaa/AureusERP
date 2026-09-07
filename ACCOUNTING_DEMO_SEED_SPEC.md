# Accounting Demo Data — Seeding Specification

**Purpose:** create a repeatable, idempotent demo dataset that lets a human manually test the entire
Aureus ERP accounting pipeline (import → validate → map → approve → journal → post → GL → TB →
statements → reconciliation) without hand-building fixtures in the UI every time.

**Audience:** the engineer/agent implementing this. Everything below is grounded in the actual
repository — file paths, class names, column names and formats were verified against source, not
assumed. Do not invent schema, columns, or UI screens.

---

## 0. Hard constraints — read before writing any code

### 0.1 Never call these seeders

| Seeder | Why it is forbidden |
|---|---|
| `Webkul\Support\Database\Seeders\CompanySeeder` | Runs `DB::table('partners_partners')->delete()`, `companies->delete()`, `users->delete()`. It will **wipe the developer's login and company**. It also swallows errors in a try/catch. |
| `Webkul\Account\Database\Seeders\AccountSeeder` | `DELETE FROM accounts_accounts` + inserts 51 rows with **hardcoded IDs 1–51**. Single-company. Conflicts with the accounting module's company-scoped `accounts_account_companies` model. |
| `Webkul\Account\Database\Seeders\JournalSeeder` | `DELETE FROM accounts_journals` + fixed IDs 1–6. |
| `Database\Seeders\CurrencySeeder` (root) | `Currency::query()->delete()` then insert. Legacy/dead — not referenced by `DatabaseSeeder`. Use `IsoCurrencySeeder` instead. |

### 0.2 Idempotency is mandatory

The developer will run this repeatedly against a **live dev database they are actively browsing**
(`aureuserp`). Every step must be safe to re-run:

- Use `firstOrCreate` / `updateOrCreate` / `insertOrIgnore`, never `delete()` + `insert()`.
- Key demo records by a stable, recognizable identifier (see §3.1) so re-runs find them.
- Never truncate. Never touch records the seeder did not create.
- Running it twice must produce the same row counts as running it once. There is a test for this (§6.3).

### 0.3 Do not modify existing accounting logic

This is a **data** task. Do not change services, models, migrations, or Filament resources except:
- adding new factory classes (§4) — additive only,
- adding new factory *states* to `BankStatementFactory` / `BankStatementLineFactory` — additive only,
- registering the new seeder (§5.4).

If a seeding step is impossible without changing business logic, **stop and report it** rather than
changing the logic.

---

## 1. Reuse map — what already exists

### 1.1 Seeders to call (all idempotent, all safe)

| Seeder | Path | Gives you |
|---|---|---|
| `Webkul\Accounting\Database\Seeders\IsoCurrencySeeder` | `plugins/webkul/accounting/database/seeders/IsoCurrencySeeder.php` | 155 ISO-4217 currencies incl. **PKR, AED, USD, EUR**. Delegates to `IsoCurrencySynchronizer` (`upsert` on `code`). |
| `Webkul\Accounting\Database\Seeders\AccountingPermissionSeeder` | same dir | All `AccountingPermissions` + grants to admin/manager/accountant roles. |
| `Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder` | same dir | 6 `ReportTemplate` rows (`bs-group`, `cashflow-group`, `ridershipline-pnl`, `op-pnl`, `tin-pnl`, `notes`). **Guards on `ReportTemplate::where('code', …)->exists()`.** |

⚠️ `ReportWorkbookSeeder` resolves companies by **name matching**: `tin` → `tin`/`truck it in`,
`rider` → `rider`, `op` → `op`/`openport`/`open port`. Unmatched companies leave the template's
entity columns with `company_id = null`. This is why §3.1 names the demo companies the way it does.

### 1.2 The Chart of Accounts file you already have

`C:\Intern\Handover\Erp\Chart_of_Accounts_Trial_Balance_Test.csv` — **already committed in the repo
root**. 62 leaf accounts, header on CSV row 5, with all eight balance columns. Two existing tests
(`CoaImportAcceptanceTest`, `CoaDependentReportsTest`) import it via `base_path()`.

**Use this file.** Do not author a new COA from scratch. Import it with:

```php
app(CoaImportService::class)->import(
    rows: $rows,                    // from CoaSheetReader → CoaHeaderDetector → CoaSheetParser
    company: $company,
    mode: 'with_journals',          // creates balanced opening/movement/adjustment migration journals
    currencyId: $company->currency_id,
    openingDate: '2026-07-01',
    movementDate: '2026-07-30',
    adjustmentDate: '2026-07-31',
);
```

`MigrationJournalService` requires `opening_date < movement_date <= adjustment_date` and throws
(rolling back the whole import) if any of the three journals is unbalanced beyond `0.005`.

Read `CoaDependentReportsTest::depRows()` / `depImport()` for the exact working call shape.

### 1.3 Other fixture files already in the repo root

| File | What it actually is | Parser |
|---|---|---|
| `ten_bank_fs_tagged_financial_model.xlsx` | 10 bank sheets + Chart of Accounts + Opening Balances + Non-Bank Entries + Transaction Mapping + FS Tag Registry. 40 txns, opening 3,800,000, closing 5,199,000, 5 transfer pairs. | `workbook_common` (XLSX only, needs explicit sheet name) |
| `Chart of Accounts - FY25 - Sheet7.csv` | **Misleading filename — it is an HBL bank statement**, not a COA. 34 txns, opening 3,250,000, closing 3,997,926. | `hbl` |
| `Customers and Vendor List  - Sheet2.csv` (note **double space**) | Two stacked sections: customers (header row 3) and vendors (header row 18). | configurable import profile |
| `legacy_opening_balances.csv`, `dummy_chart_of_accounts_matched.xlsx`, `fs_tagged_dummy_model_accounts_2025_format (1).xlsx`, `general-ledger-2026-08-01-2026-08-31.xlsx` | Additional real-world samples. | varies |

⚠️ `Chart of Accounts - FY25 - Sheet7.csv` contains stray text in an unused trailing column that
looks like shell commands. It is inert data. Do not execute it; do not copy it into any generated file.

### 1.4 Factories that already exist

`Company`, `Currency`, `Account` (+ `receivable()`/`payable()`/`expense()`/`income()` states),
`Journal` (+ `sale()`/`purchase()`/`bank()`), `Move` (+ `posted()`/`invoice()`/`vendorBill()`/…),
`MoveLine` (+ `withQuantityAndPrice()`), `Partner`, `Payment`, `BankStatement`, `BankStatementLine`.

⚠️ `CompanyFactory` defaults `is_active => fake()->boolean()` (random!) and
`currency_id => randomElement([1,2,3])` (hardcoded IDs). **Always override both.**

⚠️ `AccountFactory` does not set `is_group` and does not attach companies. Always pass
`'is_group' => false` and call `->companies()->attach($company->id)`.

⚠️ `BankStatementFactory` / `BankStatementLineFactory` only cover the **legacy `accounts` columns**.
They omit every accounting-module column (`statement_start_date`, `bank_gl_account_id`,
`import_status`, `file_hash`, `conversion_status`, `transaction_date`, `original_currency_id`, …).
See §4.2.

### 1.5 Factories that DO NOT exist (models are plain `extends Model`)

`FsTag`, `ImportProfile`, `ImportProfileMapping`, `ImportRun`, `ImportSourceRow`, `ExchangeRate`,
`ManualAdjustment`, `BankTransactionMapping`, `BusinessRule`, `CoaImportBatch`, `PartyClassification`.

Authoritative `create([...])` payload shapes for these live in:
- `plugins/webkul/accounting/tests/Feature/AccountingIntegrityGuardsTest.php` (lines ~97–160) — bank statement + line
- `plugins/webkul/accounting/tests/Feature/ApprovalEngineOptInTest.php` (lines ~72–135) — statement, line, mapping, manual adjustment
- `plugins/webkul/accounting/tests/Feature/ImportFailurePolicyAndRejectionTest.php` (lines ~88–115) — import profile + mappings

---

## 2. Deliverables

1. `plugins/webkul/accounting/database/seeders/DemoAccountingSeeder.php` — the orchestrator (§5).
2. Sub-seeders or private methods for each phase in §3. Prefer one class with well-named private
   methods over many classes, unless a phase exceeds ~80 lines.
3. New factories (§4) for the models in §1.5 that the seeder needs.
4. `database/factories` states added to `BankStatementFactory` / `BankStatementLineFactory` (§4.2).
5. `plugins/webkul/accounting/database/fixtures/hbl-demo-statement.csv` — a generated bank statement
   CSV for the demo company (§3.6, format in §7.2).
6. `plugins/webkul/accounting/database/fixtures/bank-statement-profile.json` — an importable
   `bank_statement` profile definition (§3.7, format in §7.3).
7. `plugins/webkul/accounting/tests/Feature/DemoAccountingSeederTest.php` — idempotency + integrity
   tests (§6).
8. An artisan entry point: `php artisan accounting:seed-demo` (§5.4).

---

## 3. What to seed — phase by phase

### 3.1 Phase A — Foundation

| Item | Value | Notes |
|---|---|---|
| Currencies | run `IsoCurrencySeeder` | then force `active = true` on **PKR, AED, USD, EUR** |
| Company 1 | name **`Truck It In (Demo)`**, `currency_id` = PKR | name must contain `truck it in` for `ReportWorkbookSeeder` |
| Company 2 | name **`Rider Demo`**, `currency_id` = PKR | for company-isolation testing |
| Enabled currencies | for each company: PKR (`transaction_enabled` + `reporting_enabled`), USD + AED (`transaction_enabled` only) | pivot `enabledCurrencies()` |
| User: preparer | `demo.accountant@aureus.test`, `default_company_id` = Company 1 | role: `accountant` |
| User: approver | `demo.approver@aureus.test`, `default_company_id` = Company 1 | role: `accounting_manager` |
| Permissions | run `AccountingPermissionSeeder` | grants by role name |

Both demo users: `is_active => true`, a known bcrypt password (document it in the seeder's output),
`allowedCompanies()->syncWithoutDetaching([$company->id])`.

**Idempotency key:** company `name`, user `email`.

⚠️ Do **not** touch the existing `Default Company` or the `admin@example.com` user.

### 3.2 Phase B — Chart of Accounts

For **Company 1 only**, import `Chart_of_Accounts_Trial_Balance_Test.csv` via `CoaImportService`
with `mode: 'with_journals'` (see §1.2). Expected: **62 leaf accounts**, ~130+ group nodes, and three
balanced migration journals (`coa_migration_kind` = `opening` / `movement` / `adjustment`).

For **Company 2**, import the same file with `mode: 'structure_only'` — this gives you an
identical code set in a second company, which is exactly what the "same GL code in two companies is
legal" test needs (§6.2).

**Idempotency:** `CoaImportService` matches existing leaves by `(company, code)` and groups by
`(company, source_classification_path)`, and **skips** pre-existing leaves whose `import_batch_id` is
null. Verify on a second run that leaf count does not double.

### 3.3 Phase C — FS Tags

Create ~10 FS Tags for Company 1 via `app(FsTagService::class)->create($company, [...])` (it already
handles code normalization + a `Cache::lock` against races). Suggested set, each linked to a real GL
code from the imported COA:

| Code | Name | Cash flow category |
|---|---|---|
| `FS-CUST-COLL` | Customer Collections | Operating - Receipts |
| `FS-VENDOR-PAY` | Vendor Payouts | Operating - Payments |
| `FS-BANK-FEE` | Bank Charges | Operating - Payments |
| `FS-PAYROLL` | Payroll | Operating - Payments |
| `FS-TAX-WHT` | Withholding Tax | Operating - Payments |
| `FS-TRANSFER` | Internal Transfer | Transfer |
| `FS-PROFIT` | Bank Profit Income | Operating - Receipts |
| `FS-INSURANCE` | Insurance | Operating - Payments |
| `FS-TECH` | Technology & Subscriptions | Operating - Payments |
| `FS-PROF-SVC` | Professional Services | Operating - Payments |

Also create **one inactive** tag (`FS-RETIRED`, `is_active => false`) and **one tag on Company 2**
(`FS-OTHERCO`) — these are the negative-test fixtures for "inactive FS Tag" and "wrong-company FS Tag".

### 3.4 Phase D — Journals and exchange rates

Journals for Company 1 (`firstOrCreate` on `(company_id, code)`):

| Code | Type | Name | Default account |
|---|---|---|---|
| `DBNK` | `bank` | Demo Bank Transactions | the ASSET_CASH bank GL |
| `DGEN` | `general` | Demo General Journal | any expense GL |
| `DSAL` | `sale` | Demo Customer Invoices | the ASSET_RECEIVABLE GL |
| `DPUR` | `purchase` | Demo Vendor Bills | the LIABILITY_PAYABLE GL |

Prefer `app(BankJournalCreationService::class)->create($company, [...])` for the bank journal — it
validates currency/account compatibility. See `AccountingIntegrityGuardsTest::integrityFixture()`.

Exchange rates (`accounting_exchange_rates`) for Company 1, **approved**:

| From | To | Date | Rate | rate_type | source |
|---|---|---|---|---|---|
| USD | PKR | 2026-07-01 | 278.50 | Transaction | Manual |
| USD | PKR | 2026-08-01 | 281.25 | Transaction | Manual |
| AED | PKR | 2026-07-01 | 75.80 | Transaction | Manual |

Two USD rates on different dates is deliberate — it is the fixture for the "historical rate is not
overwritten by the latest rate" test.

Set `approval_status` to the Approved enum value; `ExchangeRateService::findCandidate()` ignores
unapproved rates.

### 3.5 Phase E — Partners, invoices, bills

**Partners** for Company 1 (`firstOrCreate` on `(company_id, reference)`):

- 4 customers: `CUST-DEMO-01..04`, `customer_rank => 1`, `property_account_receivable_id` = the AR GL.
- 5 vendors: `VEND-DEMO-01..05`, `supplier_rank => 1`, `property_account_payable_id` = the AP GL.

**Customer invoices** (Company 1, journal `DSAL`, `MoveType::OUT_INVOICE`). Build with
`Move::factory()` + `MoveLine::factory()->withQuantityAndPrice(...)` then
`AccountFacade::confirmMove($invoice->fresh())` — copy the exact pattern from
`BankInvoiceReconciliationTest::bankInvoiceReconciliationFixture()`.

| Ref | Partner | Amount (PKR) | Date | Due | Intended end state |
|---|---|---|---|---|---|
| `INV-DEMO-1001` | CUST-DEMO-01 | 300,000 | 2026-07-05 | 2026-08-04 | partially paid (200,000 via bank) |
| `INV-DEMO-1002` | CUST-DEMO-02 | 150,000 | 2026-07-10 | 2026-08-09 | fully paid via bank |
| `INV-DEMO-1003` | CUST-DEMO-03 | 425,000 | 2026-06-01 | 2026-07-01 | **left unpaid and overdue** (aging fixture) |
| `INV-DEMO-1004` | CUST-DEMO-04 | 88,000 | 2026-07-20 | 2026-08-19 | unpaid, not yet due |

Set `booking_id` and `consolidated_number` on `INV-DEMO-1001` (e.g. `BKG-1001`, `CON-1001`) — these
are the fixtures for the invoice-matching priority chain.

**Vendor bills** (journal `DPUR`, `MoveType::IN_INVOICE`):

| Ref | Partner | Amount (PKR) | Date | Due | Intended end state |
|---|---|---|---|---|---|
| `BILL-DEMO-2001` | VEND-DEMO-01 | 120,000 | 2026-07-08 | 2026-08-07 | partially paid (80,000) |
| `BILL-DEMO-2002` | VEND-DEMO-02 | 64,000 | 2026-06-15 | 2026-07-15 | **unpaid and overdue** |

### 3.6 Phase F — Bank statement + mappings

Generate `plugins/webkul/accounting/database/fixtures/hbl-demo-statement.csv` in the **HBL parser
format** (§7.2) with ~12 transactions covering every scenario a tester needs:

| # | Date | Description | Dr | Cr | Purpose |
|---|---|---|---|---|---|
| 1 | 2026-07-06 | `IBFT CR - Customer collection INV-DEMO-1002` | | 150,000 | full invoice match |
| 2 | 2026-07-07 | `IBFT CR - Customer collection INV-DEMO-1001` | | 200,000 | partial invoice match |
| 3 | 2026-07-09 | `Online transfer - Vendor payment BILL-DEMO-2001` | 80,000 | | partial bill match |
| 4 | 2026-07-12 | `Bank service charges - July` | 2,500 | | expense, `FS-BANK-FEE` |
| 5 | 2026-07-12 | `FED on bank service charges` | 400 | | expense, `FS-BANK-FEE` |
| 6 | 2026-07-15 | `Transfer to Demo Payroll Account` | 500,000 | | internal transfer out |
| 7 | 2026-07-15 | `Transfer from Demo Operating Account` | | 500,000 | internal transfer in (pair with #6) |
| 8 | 2026-07-18 | `Card 4411 - Cloud services subscription` | 36,500 | | expense, `FS-TECH` |
| 9 | 2026-07-22 | `Online transfer - Insurance premium` | 180,000 | | expense, `FS-INSURANCE` |
| 10 | 2026-07-28 | `Profit credited on current account` | | 18,450 | income, `FS-PROFIT` |
| 11 | 2026-07-29 | `Advance tax on bank profit` | 2,768 | | `FS-TAX-WHT` |
| 12 | 2026-07-30 | `MISC DR 88213` | 5,000 | | **deliberately unmappable** — must stay `NeedsReview` |

Rules for the file: opening/closing balances in the metadata block must reconcile
(`opening + credits − debits == closing`, tolerance 0.01) or `BankStatementValidationService` flips
`import_status` to `ReconciliationFailed`. Every row must have **exactly one** of Dr/Cr positive.
The running balance column must actually roll forward.

Then import it through `BankStatementImportService::import()` and leave the resulting
`BankTransactionMapping` rows in **mixed states** so the tester has something to do:

- rows 1–3: `review_status = Suggested`, matched to their invoice/bill, **not yet approved**
- rows 4–5, 8–11: `review_status = Approved` with offset GL + FS Tag set, **not yet posted**
- rows 6–7: linked as a `accounting_bank_transfer_matches` pair, status `suggested`
- row 12: `review_status = NeedsReview`, no offset GL

Do **not** post any of them. Posting is what the human tester is supposed to do.

### 3.7 Phase G — Import profiles

The dev DB currently has only two `opening_balance` profiles and **no `bank_statement` profile** —
this is a real gap that makes half the configurable-import path untestable.

1. Write `plugins/webkul/accounting/database/fixtures/bank-statement-profile.json` conforming
   exactly to the schema in §7.3 (`schema_version` must be the **integer** `1`).
2. In the seeder, create the equivalent `ImportProfile` + `ImportProfileMapping` rows directly for
   Company 1, named `Demo HBL Bank Statement (CSV)`, `is_active => true`.
3. Also create one `BusinessRule` for Company 1 with a `mark_non_critical` action so the
   Warn-and-Continue failure policy is demonstrable (see §7.4).

Required target fields for `bank_statement`: `date`, `currency`, `bank_account_number`,
`description`, `journal_code`, `bank_gl_code`. Constants (currency, bank account number, journal
code, bank GL code) are injected with `source_header: null` + a `default` transformation.

⚠️ `ImportExecutionService::createBankStatement()` requires **every row in the file to share one**
`currency`, `bank_account_number`, `journal_code` and `bank_gl_code`.
⚠️ `journal_code` must resolve to a `JournalType::BANK` journal; `bank_gl_code` must resolve to an
active postable `AccountType::ASSET_CASH` account owned by the company.

### 3.8 Phase H — Approval workflow

Create, for Company 1:

- An `ApprovalWorkflow` (`support_approval_workflows`), `request_type = 'bank_transaction_mapping'`,
  `is_active => true`, `minimum_amount => 100000` (so only large mappings need approval — this
  demonstrates amount-based routing).
- One `ApprovalStep` (`support_approval_steps`), `sequence => 1`, `approver_user_id` = the demo
  approver user, `required_approvals => 1`.
- A second workflow for `request_type = 'manual_adjustment'`, no amount bounds, one step.

This exercises the opt-in `ApprovalEngine` gate in `BankMappingService::approve()` and
`ManualAdjustmentService::approve()`.

⚠️ `ApprovalStep::booted()` enforces that **exactly one** of `approver_user_id` /
`approver_role_id` / `hierarchy_route` is set.

### 3.9 Phase I — Manual adjustment

One `ManualAdjustment` for Company 1, `approval_status = Draft`, amount 20,000, debit/credit two
different postable GLs, so the tester can walk submit → approve → draft → post.

---

## 4. Factories to add

Add under `plugins/webkul/accounting/database/factories/`, namespace
`Webkul\Accounting\Database\Factories`. Each model needs `use HasFactory;` added **and** a
`newFactory()` override if the namespace doesn't match Laravel's default resolution (the accounting
plugin's existing factories, e.g. `ReportTemplateFactory`, show the working pattern — copy it).

### 4.1 New factories

| Factory | Key defaults |
|---|---|
| `FsTagFactory` | `company_id => Company::factory()`, `code => 'FS-'.strtoupper(unique lexify('????'))`, `name`, `is_active => true` |
| `ImportProfileFactory` | `entity_type => 'bank_statement'`, `file_type => 'csv'`, `header_row => 1`, `data_start_row => 2`, `skip_rows => 0`, `blank_row_rule => 'skip'`, `failure_policy => 'reject_rows'`, `delimiter => ','`, `encoding => 'UTF-8'`, `version => 1`, `is_active => true`; states `openingBalance()`, `bankStatement()`, and one per `ImportFailurePolicy` |
| `ImportProfileMappingFactory` | `position`, `target_field`, `is_required => false`, `transformations => []`, `validation_rules => []` |
| `ExchangeRateFactory` | `rate_type`, `source`, `approval_status`; state `approved()` |
| `BankTransactionMappingFactory` | `review_status => BankReviewStatus::Unmapped`, `posting_status => BankPostingStatus::NotPosted` |
| `ManualAdjustmentFactory` | `approval_status => ManualAdjustmentStatus::Draft`, `amount`, `date` |
| `BusinessRuleFactory` | `conditions => []`, `actions => []`, `stop_processing => false`, `is_active => true` — both JSON columns are `NOT NULL` |

### 4.2 States to add to existing factories

Add to `plugins/webkul/accounts/database/factories/BankStatementFactory.php` a state
`accountingModule(array $overrides = [])` that fills the accounting-module columns the six accounting
test files currently set by hand: `company_currency_id`, `bank_gl_account_id`, `statement_start_date`,
`statement_end_date`, `opening_balance`, `total_debits`, `total_credits`, `closing_balance`,
`company_opening_balance`, `company_total_debits`, `company_total_credits`,
`company_closing_balance`, `conversion_status`, `bank_name`, `bank_account_number`, `account_title`,
`original_filename`, `file_hash`, `parser`, `import_status`.

Same for `BankStatementLineFactory`: a state filling `transaction_date`, `value_date`, `description`,
`reference`, `original_currency_id`, `company_currency_id`, `original_debit`, `original_credit`,
`original_signed_amount`, `company_debit`, `company_credit`, `company_signed_amount`,
`running_balance`, `import_status`, `transaction_fingerprint`.

Copy the exact payloads from `AccountingIntegrityGuardsTest::createIntegrityStatementLine()`.

---

## 5. The seeder

### 5.1 Class

`plugins/webkul/accounting/database/seeders/DemoAccountingSeeder.php`,
namespace `Webkul\Accounting\Database\Seeders`.

### 5.2 Structure

```php
public function run(): void
{
    $this->call([IsoCurrencySeeder::class, AccountingPermissionSeeder::class]);

    $currencies = $this->activateCurrencies();          // Phase A
    $companies  = $this->seedCompanies($currencies);
    $users      = $this->seedUsers($companies);
    $coa        = $this->importChartOfAccounts($companies);   // Phase B
    $tags       = $this->seedFsTags($companies, $coa);        // Phase C
    $journals   = $this->seedJournals($companies, $coa);      // Phase D
    $this->seedExchangeRates($companies, $currencies);
    $parties    = $this->seedPartners($companies, $coa);      // Phase E
    $docs       = $this->seedInvoicesAndBills($companies, $journals, $parties, $coa);
    $this->seedBankStatement($companies, $journals, $coa, $tags, $docs);   // Phase F
    $this->seedImportProfiles($companies, $journals, $coa);   // Phase G
    $this->seedApprovalWorkflows($companies, $users);         // Phase H
    $this->seedManualAdjustment($companies, $coa);            // Phase I

    $this->call(ReportWorkbookSeeder::class);
    $this->printSummary();
}
```

Each private method must be individually idempotent and must `$this->command?->info(...)` what it
created vs. found, so a re-run visibly reports `created: 0`.

### 5.3 Transaction boundaries

Wrap **each phase** in its own `DB::transaction`, not the whole run. A failure in Phase F should not
roll back a successful COA import from Phase B. Report which phase failed.

### 5.4 Artisan entry point

Add `app/Console/Commands/SeedAccountingDemo.php` (matching the existing
`SyncAccountingPermissions` / `SyncAccountingCurrencies` commands in that directory):

```
php artisan accounting:seed-demo [--fresh]
```

`--fresh` is **not** a database wipe — it means "delete only the demo records this seeder created
(matched by the §3.1 identifiers) and recreate them". Never `migrate:fresh`. If `--fresh` cannot be
implemented safely for a given phase, omit it for that phase and say so in the command output.

Also register `DemoAccountingSeeder` in `AccountingServiceProvider::hasSeeders()` **only if** it can
be made safe for `accounting:install` to run automatically. If in doubt, leave it out of
`hasSeeders()` and require the explicit artisan command — a demo seeder running during a production
install would be worse than an inconvenient extra step.

---

## 6. Tests

`plugins/webkul/accounting/tests/Feature/DemoAccountingSeederTest.php`. Follow the existing accounting
test conventions: Pest `it(...)`, `DatabaseTransactions` (already applied globally in `tests/Pest.php`),
no `TestBootstrapHelper` needed for non-Filament tests.

### 6.1 Seeds a complete, coherent dataset

- Both demo companies exist with PKR base currency.
- Company 1 has 62 postable accounts and >100 group accounts.
- Company 1 has ≥10 active FS Tags, plus exactly 1 inactive one.
- The three migration journals exist, are **posted**, and each balances (Dr == Cr).
- 4 customer invoices and 2 vendor bills exist and are posted.
- ≥12 `BankTransactionMapping` rows exist, **none posted**, with at least one in each of
  `Suggested` / `Approved` / `NeedsReview`.
- At least one `ApprovalWorkflow` with one `ApprovalStep` exists for each of
  `bank_transaction_mapping` and `manual_adjustment`.

### 6.2 Company isolation holds

- The same GL code exists in both companies and resolves to **different** account IDs.
- Company 2 cannot see Company 1's FS Tags: `FsTagService::resolve($company2->id, 'FS-BANK-FEE')`
  returns `null`.

### 6.3 Idempotency

Run the seeder **twice** in one test. Assert that after the second run:

- `accounts_accounts` count for Company 1 is unchanged,
- `accounting_fs_tags` count is unchanged,
- `accounts_account_moves` count is unchanged,
- `accounting_bank_transaction_mappings` count is unchanged,
- `support_approval_workflows` count is unchanged.

### 6.4 Trial Balance integrity

After seeding, `app(TrialBalanceService::class)->compute($company1->id, $from, $to)` must return
`closing_debit == closing_credit` (difference 0). This is the single most valuable assertion in the
file — it proves the seeded ledger is internally consistent.

### 6.5 Running the tests

```bash
php vendor/bin/pest --colors=never plugins/webkul/accounting/tests/Feature/DemoAccountingSeederTest.php
```

PHP lives at `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`; MySQL at
`C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin`. If MySQL is not running, start it with
`Start-Process "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe" -ArgumentList '--defaults-file="C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini"'`.
Tests run against `aureuserp_testing`; the seeder run by a human targets `aureuserp`.

---

## 7. Format reference

### 7.1 Chart of Accounts CSV

- Header row must contain cells matching **exactly** (case-insensitive, trimmed, whole-cell):
  `Nature`, `Code`, `Title`. `"GL Code"` / `"Account Title"` will **not** match.
- Hierarchy columns: any header starting with `classification` (e.g. `Classification 1`…`7`).
  Consumed in column order; the number only matters for the `classification_1..7` DB columns.
- Group path = `Nature` first, then each non-empty classification, consecutive duplicates collapsed.
- **No column marks a row as a group.** Every data row becomes a postable leaf; group nodes are
  synthesized from distinct path prefixes.
- Balance columns: **all eight or none**, positionally after `Title`, in the fixed order
  Opening Dr, Opening Cr, Movement Dr, Movement Cr, Adjustment Dr, Adjustment Cr, Closing Dr,
  Closing Cr. Labels are never read. A partial layout throws.
- CSV gotcha: a trailing comma on the header line makes `columnsAfterTitle == 1` and throws.
  In a structure-only CSV, `Title` must be the genuinely last field.
- `Nature` is a free string. Real data uses `B/S` and `P&L`. Only the literal lowercase `p&l` is
  special-cased (as an `AccountType::EXPENSE` fallback and in a warning rule).
- AccountType is chosen by `CoaAccountTypeMapper::suggest()` — first match over
  `mb_strtolower(classificationPath . ' ' . title)` against an ordered needle list
  (`cash & bank`, `cash at bank`, `trade debt`, `receivable`, `accumulated depreciation`, … ,
  `finance cost`), else a Classification-1 fallback.

**Hard errors (block import):** `empty_code`, `empty_title`, `duplicate_code` (same code twice in
one file).
**Warnings (non-blocking):** `non_numeric_code`, `possible_misspelling` (`libailities`,
`intanbgible`, `guarrantee`), `duplicate_title`, `nature_section_mismatch`,
`input_tax_under_liability`, `provision_under_asset`, `cost_as_revenue` (title contains `trucker`
and Classification 1 contains `revenue`).

Any generated COA fixture must produce **zero warnings**: numeric unique codes, unique titles, no
`trucker`/`input sales tax`/`provision for taxation` titles in the wrong section.

### 7.2 HBL bank statement CSV

Metadata is **positional** — column B rows 2–5 and column G rows 2–5:

```
HBL — Raw Bank Statement,,,,,,
Account title,Truck It In (Demo),,,,Opening balance,"1,000,000"
Account number,PK55DEMO0000000000000001,,,,Total debits,"807,168"
Statement period,01-Jul-2026 to 31-Jul-2026,,,,Total credits,"868,450"
Currency,PKR,,,,Closing balance,"1,061,282"
,,,,,,
Transaction Date,Value Date,Description,Reference,Debit (PKR),Credit (PKR),Balance (PKR)
06-Jul-2026,06-Jul-2026,IBFT CR - Customer collection INV-DEMO-1002,DEMO260706001,,"150,000","1,150,000"
```

- Detection: filename / first line / sheet name must contain `hbl` (case-insensitive).
- Header row detected by: exact `transaction date` **and** exact `description` **and** a cell
  starting with `debit` **and** a cell starting with `credit`.
- Transaction columns are **strictly positional**, 0-indexed:
  `0` Transaction Date, `1` Value Date, `2` Description, `3` Reference, `4` Debit, `5` Credit,
  `6` Running Balance. Header text on columns 4/5 is irrelevant.
- Account number (cell B3) must be non-empty or the parser throws.
- Dates: Excel serial, or anything `Carbon::parse()` accepts (`01-Jul-2026` works).
- Numbers: non-numeric characters stripped; parentheses = negative; `''` and `-` → 0.
- **FS Tag column:** `BankStatementImportService` searches `rawHeader` for a cell **strictly equal**
  (`===`, case-sensitive) to the literal `'FS Tag'`. `FS TAG`, `Fs Tag`, `FS Tag ` (trailing space)
  and `FS Tag Code` all silently yield no tag. Put extra columns after column 6 if you want FS Tag
  resolution on import.
- Offset GL is **not** read from `rawRow` on this path — only on the profile-driven path from
  `transformed_values['offset_gl_code']`.

### 7.3 Import profile JSON

```json
{
  "schema_version": 1,
  "profile": {
    "name": "…", "entity_type": "bank_statement", "file_type": "csv",
    "sheet_name": null, "header_row": 7, "data_start_row": 8, "skip_rows": 0,
    "blank_row_rule": "skip", "failure_policy": "reject_rows",
    "stop_rule": null, "delimiter": ",", "encoding": "UTF-8", "version": 1
  },
  "mappings": [
    { "position": 1, "source_header": "Transaction Date", "source_position": null,
      "source_aliases": ["Txn Date", "Date"], "target_field": "date",
      "transformations": [{ "type": "trim" }, { "type": "date", "format": "d-M-Y", "output": "Y-m-d" }],
      "validation_rules": ["date"], "is_required": true }
  ],
  "rules": []
}
```

- `schema_version` must be integer `1` (strict `!==` check — `"1"` fails).
- `file_type` ∈ `csv | xlsx | xls`.
- Every mapping's `target_field` must exist in `ImportEntityRegistry::fields($entity_type)`;
  `(profile_id, target_field)` is unique.
- `blank_row_rule` ∈ `skip | stop`; `failure_policy` ∈
  `reject_file | reject_rows | flag_review | warn_continue`.
- On import the service **overrides** `company_id`, `owner_id`, `version`, and forces
  `is_active = false` — imported profiles must be activated manually.

Header matching: `Str::of($h)->squish()->lower()->replaceMatches('/[^a-z0-9]+/','_')->trim('_')`.
So `"Debit (PKR)"` → `debit_pkr`. Matching is normalized-exact, not fuzzy. `source_position`
(1-based) short-circuits header matching entirely.

Transformation whitelist: `trim, upper, lower, title, date, decimal, boolean, null_if, default,
map, concat, split, find_replace, regex_replace`. Anything else is a row error.
Validation rules: only `email, date, numeric, boolean`.

### 7.4 Business rule (for Warn-and-Continue)

`accounting_business_rules` — `conditions` and `actions` are both `NOT NULL` JSON.

```json
{
  "conditions": [{ "field": "counterparty", "operator": "blank" }],
  "actions":    [{ "type": "mark_non_critical", "field": "counterparty" }]
}
```

Condition operators: `equals, not_equals, contains, starts_with, ends_with, greater_than,
less_than, blank, not_blank, in` (all ANDed — there is no OR).
Actions: `set, copy, default, map`, plus `mark_non_critical` (recognized as a no-op by
`ConditionalRuleEngine`, interpreted by `ImportPreviewService` only under the `warn_continue` policy,
and never applied to hard-integrity fields).

---

## 8. Definition of done

- [ ] `php artisan accounting:seed-demo` completes with no errors against `aureuserp`.
- [ ] Running it a **second** time reports 0 created for every phase and changes no row counts.
- [ ] `DemoAccountingSeederTest` passes (all of §6).
- [ ] `vendor/bin/pint` clean on every changed/added file.
- [ ] `git diff --check` clean.
- [ ] No existing test regressed: run
      `php vendor/bin/pest --colors=never plugins/webkul/accounting/tests/Feature` and compare
      against the known baseline of **2 pre-existing failures**
      (`BankAccountingWorkflowTest` "rejects disabled currencies…" needs `ACCOUNTING_WORKBOOK_FIXTURE`;
      `CoaUploadPathResolverTest` "preserves XLSX support…" needs the PHP `zip` extension) and
      **14 pre-existing skips**.
- [ ] The developer can log in as `demo.accountant@aureus.test`, switch to `Truck It In (Demo)`, and
      immediately see: a populated Chart of Accounts, FS Tags, unposted bank mappings in mixed
      review states, open invoices/bills, and a balanced Trial Balance.

---

## 9. Report back

When done, report only:

1. Files added/changed (paths).
2. What the seeder creates, as a table of counts.
3. Test results (pass/fail counts, and any failure verbatim).
4. Anything in this spec that turned out to be wrong about the repository — flag it explicitly rather
   than silently working around it.
5. Anything you could not implement and why.
