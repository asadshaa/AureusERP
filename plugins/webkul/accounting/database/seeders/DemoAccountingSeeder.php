<?php

namespace Webkul\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\BankTransferMatch;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportProfileMapping;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * Idempotent demo dataset for the accounting module. Every phase is safe to
 * re-run: nothing is ever deleted or truncated, records are matched by a
 * stable business key (company name, user email, GL code, journal code,
 * FS Tag code, move reference, ...), and pre-existing records the seeder did
 * not create are never touched.
 *
 * See ACCOUNTING_DEMO_SEED_SPEC.md at the repository root for the full
 * rationale behind every phase.
 */
class DemoAccountingSeeder extends Seeder
{
    /**
     * The GL code deliberately re-typed to LIABILITY_PAYABLE via CoaImportService's
     * $typeOverrides. The sample Chart of Accounts CSV never produces a
     * LIABILITY_PAYABLE account on its own (CoaAccountTypeMapper only ever
     * maps "payable"/"accrued"/"liabilit*" needles to LIABILITY_CURRENT), but
     * the demo needs one real payable-obligation account so that vendor bills
     * can be posted, matched by BankMatchingPriorityService, and reconciled.
     * "Accrued expenses" already carries a non-zero opening/adjustment balance
     * in the source sheet, which is left untouched by the override.
     */
    protected const AP_GL_CODE = '1035';

    protected const BANK_GL_CODE = '1028';

    protected const AR_GL_CODE = '1018';

    protected const REVENUE_GL_CODE = '1050';

    protected const GENERIC_EXPENSE_GL_CODE = '1068';

    protected const SECONDARY_BANK_GL_CODE = '1029';

    /**
     * Track stats across phases for the final report.
     *
     * @var array<string, array{created: int, existing: int}>
     */
    protected array $stats = [];

    public function run(): void
    {
        $this->call([
            IsoCurrencySeeder::class,
            AccountingPermissionSeeder::class,
        ]);

        $currencies = $this->activateCurrencies();
        $companies = $this->seedCompanies($currencies);
        $users = $this->seedUsers($companies);
        $this->importChartOfAccounts($companies);
        $tags = $this->seedFsTags($companies);
        $journals = $this->seedJournals($companies);
        $this->seedExchangeRates($companies, $currencies);
        $parties = $this->seedPartners($companies);
        $docs = $this->seedInvoicesAndBills($companies, $journals, $parties, $users);
        $this->seedBankStatement($companies, $journals, $currencies, $users, $docs);
        $this->seedImportProfiles($companies, $users);
        $this->seedApprovalWorkflows($companies, $users);
        $this->seedManualAdjustment($companies, $users);

        $this->call(ReportWorkbookSeeder::class);

        $this->printSummary();
    }

    /**
     * Phase A: activate the demo's transaction/reporting currencies.
     * IsoCurrencySeeder already marks every current ISO fiat currency
     * (including PKR/AED/USD/EUR) active; this is a defensive re-assertion
     * in case any of the four were manually deactivated in the dev DB.
     *
     * @return array<string, Currency>
     */
    protected function activateCurrencies(): array
    {
        return DB::transaction(function () {
            $currencies = [];
            foreach (['PKR', 'AED', 'USD', 'EUR'] as $code) {
                $currency = Currency::query()->where('code', $code)->firstOrFail();
                $currency->update(['active' => true, 'is_iso_fiat' => true]);
                $currencies[$code] = $currency;
            }

            return $currencies;
        });
    }

    /**
     * Phase A: the two demo companies and their enabled-currency pivots.
     *
     * @param  array<string, Currency>  $currencies
     * @return array{company1: Company, company2: Company}
     */
    protected function seedCompanies(array $currencies): array
    {
        return DB::transaction(function () use ($currencies) {
            $created = 0;
            $existing = 0;

            $company1 = Company::query()->where('name', 'Truck It In (Demo)')->first();
            if (! $company1) {
                $company1 = Company::query()->create([
                    'name'        => 'Truck It In (Demo)',
                    'currency_id' => $currencies['PKR']->id,
                    'is_active'   => true,
                ]);
                $created++;
            } else {
                $existing++;
            }

            $company2 = Company::query()->where('name', 'Rider Demo')->first();
            if (! $company2) {
                $company2 = Company::query()->create([
                    'name'        => 'Rider Demo',
                    'currency_id' => $currencies['PKR']->id,
                    'is_active'   => true,
                ]);
                $created++;
            } else {
                $existing++;
            }

            foreach ([$company1, $company2] as $company) {
                $company->enabledCurrencies()->syncWithoutDetaching([
                    $currencies['PKR']->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
                    $currencies['USD']->id => ['transaction_enabled' => true, 'reporting_enabled' => false],
                    $currencies['AED']->id => ['transaction_enabled' => true, 'reporting_enabled' => false],
                ]);
            }

            $this->recordStat('Companies', $created, $existing);

            return compact('company1', 'company2');
        });
    }

    /**
     * Phase A: the two demo users (preparer/approver) and their roles.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @return array{accountant: User, approver: User}
     */
    protected function seedUsers(array $companies): array
    {
        return DB::transaction(function () use ($companies) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;

            // AccountingPermissionRegistrar only grants permissions to roles
            // that already exist; it never creates them.
            Role::query()->firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
            Role::query()->firstOrCreate(['name' => 'accounting_manager', 'guard_name' => 'web']);
            app(AccountingPermissionRegistrar::class)->synchronize();

            $accountant = User::query()->where('email', 'demo.accountant@aureus.test')->first();
            if (! $accountant) {
                $accountant = User::query()->create([
                    'name'               => 'Demo Accountant',
                    'email'              => 'demo.accountant@aureus.test',
                    'password'           => Hash::make('password'),
                    'default_company_id' => $company1->id,
                    'is_active'          => true,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $accountant->allowedCompanies()->syncWithoutDetaching([$company1->id]);
            if (! $accountant->hasRole('accountant')) {
                $accountant->assignRole('accountant');
            }

            $approver = User::query()->where('email', 'demo.approver@aureus.test')->first();
            if (! $approver) {
                $approver = User::query()->create([
                    'name'               => 'Demo Approver',
                    'email'              => 'demo.approver@aureus.test',
                    'password'           => Hash::make('password'),
                    'default_company_id' => $company1->id,
                    'is_active'          => true,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $approver->allowedCompanies()->syncWithoutDetaching([$company1->id]);
            if (! $approver->hasRole('accounting_manager')) {
                $approver->assignRole('accounting_manager');
            }

            $this->recordStat('Users', $created, $existing);

            return compact('accountant', 'approver');
        });
    }

    /**
     * Phase B: import the Chart of Accounts CSV into both demo companies.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     */
    protected function importChartOfAccounts(array $companies): void
    {
        DB::transaction(function () use ($companies) {
            $company1 = $companies['company1'];
            $company2 = $companies['company2'];

            $coaFile = base_path('Chart_of_Accounts_Trial_Balance_Test.csv');
            if (! file_exists($coaFile)) {
                throw new \RuntimeException("Chart of Accounts fixture not found at: {$coaFile}");
            }

            $rawRows = (new CoaSheetReader)->read($coaFile);
            $parsedRows = (new CoaSheetParser)->parse($rawRows, (new CoaHeaderDetector)->detect($rawRows));

            // CoaImportService's MigrationJournalService requires at least one
            // journal to already exist for the company before it can post the
            // opening/movement/adjustment migration entries. The real DGEN
            // journal (with its GL-linked default account) can only be
            // finalised in Phase D, once the COA import has produced the
            // account it should point to — so seed a minimal shell here and
            // fill in its default_account_id afterwards.
            Journal::query()->firstOrCreate(
                ['company_id' => $company1->id, 'code' => 'DGEN'],
                [
                    'name'        => 'Demo General Journal',
                    'type'        => JournalType::GENERAL->value,
                    'currency_id' => $company1->currency_id,
                ],
            );

            $typeOverrides = [self::AP_GL_CODE => AccountType::LIABILITY_PAYABLE->value];

            $created = 0;
            $existing = 0;

            // Company 1: with_journals — produces the 62 leaves, >100 groups,
            // and three balanced posted migration journals.
            $hasMigrationMoves = Move::query()
                ->where('company_id', $company1->id)
                ->whereNotNull('coa_migration_kind')
                ->exists();

            if (! $hasMigrationMoves) {
                app(CoaImportService::class)->import(
                    rows: $parsedRows,
                    company: $company1,
                    typeOverrides: $typeOverrides,
                    mode: 'with_journals',
                    currencyId: $company1->currency_id,
                    openingDate: '2026-07-01',
                    movementDate: '2026-07-30',
                    adjustmentDate: '2026-07-31',
                );
                $created += 62;
            } else {
                $existing += 62;
            }

            // Company 2: structure_only — same GL codes, independent account
            // rows, no migration journals. Proves same-code/two-companies is legal.
            $company2LeafExists = Account::query()
                ->whereHas('companies', fn ($q) => $q->where('companies.id', $company2->id))
                ->where('is_group', false)
                ->exists();

            if (! $company2LeafExists) {
                app(CoaImportService::class)->import(
                    rows: $parsedRows,
                    company: $company2,
                    typeOverrides: $typeOverrides,
                    mode: 'structure_only',
                    currencyId: $company2->currency_id,
                );
                $created += 62;
            } else {
                $existing += 62;
            }

            // AccountManager::isReconciliationAllowedForLines() only allows
            // reconciliation on ASSET_CASH/LIABILITY_CREDIT_CARD accounts or
            // accounts explicitly flagged reconcile=true. CoaImportService
            // never sets that flag, so the AR/AP control accounts must be
            // marked reconcilable here or bank-obligation matching can never
            // be posted by the human tester later.
            Account::query()
                ->whereHas('companies', fn ($q) => $q->whereIn('companies.id', [$company1->id, $company2->id]))
                ->whereIn('code', [self::AR_GL_CODE, self::AP_GL_CODE])
                ->update(['reconcile' => true]);

            $this->recordStat('Chart of Accounts Leaves', $created, $existing);
        });
    }

    /**
     * Phase C: FS Tags, each linked to a real GL code from the imported COA.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @return array<string, FsTag>
     */
    protected function seedFsTags(array $companies): array
    {
        return DB::transaction(function () use ($companies) {
            $company1 = $companies['company1'];
            $company2 = $companies['company2'];
            $created = 0;
            $existing = 0;
            $tags = [];

            $definitions = [
                ['code' => 'FS-CUST-COLL', 'name' => 'Customer Collections', 'category' => CashFlowCategory::OperatingReceipts, 'gl' => self::AR_GL_CODE],
                ['code' => 'FS-VENDOR-PAY', 'name' => 'Vendor Payouts', 'category' => CashFlowCategory::OperatingPayments, 'gl' => self::AP_GL_CODE],
                ['code' => 'FS-BANK-FEE', 'name' => 'Bank Charges', 'category' => CashFlowCategory::OperatingPayments, 'gl' => '1057'],
                ['code' => 'FS-PAYROLL', 'name' => 'Payroll', 'category' => CashFlowCategory::OperatingPayments, 'gl' => '1056'],
                ['code' => 'FS-TAX-WHT', 'name' => 'Withholding Tax', 'category' => CashFlowCategory::OperatingPayments, 'gl' => '1045'],
                ['code' => 'FS-TRANSFER', 'name' => 'Internal Transfer', 'category' => CashFlowCategory::Transfer, 'gl' => self::SECONDARY_BANK_GL_CODE],
                ['code' => 'FS-PROFIT', 'name' => 'Bank Profit Income', 'category' => CashFlowCategory::OperatingReceipts, 'gl' => '1066'],
                ['code' => 'FS-INSURANCE', 'name' => 'Insurance', 'category' => CashFlowCategory::OperatingPayments, 'gl' => '1058'],
                ['code' => 'FS-TECH', 'name' => 'Technology & Subscriptions', 'category' => CashFlowCategory::OperatingPayments, 'gl' => self::GENERIC_EXPENSE_GL_CODE],
                ['code' => 'FS-PROF-SVC', 'name' => 'Professional Services', 'category' => CashFlowCategory::OperatingPayments, 'gl' => '1060'],
            ];

            foreach ($definitions as $def) {
                $tag = FsTag::query()->where('company_id', $company1->id)->where('code', $def['code'])->first();
                if (! $tag) {
                    $tag = app(FsTagService::class)->create($company1, [
                        'code'               => $def['code'],
                        'name'               => $def['name'],
                        'cash_flow_category' => $def['category']->value,
                        'account_id'         => $this->glAccount($company1, $def['gl'])->id,
                        'is_active'          => true,
                    ]);
                    $created++;
                } else {
                    $existing++;
                }
                $tags[$def['code']] = $tag;
            }

            // Negative fixture 1: one inactive tag on Company 1.
            $retired = FsTag::query()->where('company_id', $company1->id)->where('code', 'FS-RETIRED')->first();
            if (! $retired) {
                $retired = app(FsTagService::class)->create($company1, [
                    'code'               => 'FS-RETIRED',
                    'name'               => 'Retired FS Tag',
                    'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
                    'account_id'         => $this->glAccount($company1, '1059')->id,
                    'is_active'          => false,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $tags['FS-RETIRED'] = $retired;

            // Negative fixture 2: one tag scoped to Company 2 only.
            $otherCo = FsTag::query()->where('company_id', $company2->id)->where('code', 'FS-OTHERCO')->first();
            if (! $otherCo) {
                $otherCo = app(FsTagService::class)->create($company2, [
                    'code'               => 'FS-OTHERCO',
                    'name'               => 'Other Company FS Tag',
                    'cash_flow_category' => CashFlowCategory::OperatingReceipts->value,
                    'account_id'         => $this->glAccount($company2, self::AR_GL_CODE)->id,
                    'is_active'          => true,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $tags['FS-OTHERCO'] = $otherCo;

            $this->recordStat('FS Tags', $created, $existing);

            return $tags;
        });
    }

    /**
     * Phase D: the four demo journals for Company 1.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @return array<string, Journal>
     */
    protected function seedJournals(array $companies): array
    {
        return DB::transaction(function () use ($companies) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;
            $journals = [];

            $bankGl = $this->glAccount($company1, self::BANK_GL_CODE);
            $arGl = $this->glAccount($company1, self::AR_GL_CODE);
            $apGl = $this->glAccount($company1, self::AP_GL_CODE);
            $expenseGl = $this->glAccount($company1, self::GENERIC_EXPENSE_GL_CODE);

            $dbnk = Journal::query()->where('company_id', $company1->id)->where('code', 'DBNK')->first();
            if (! $dbnk) {
                $dbnk = app(BankJournalCreationService::class)->create($company1, [
                    'name'               => 'Demo Bank Transactions',
                    'code'               => 'DBNK',
                    'currency_id'        => $company1->currency_id,
                    'default_account_id' => $bankGl->id,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $journals['DBNK'] = $dbnk;

            $dgen = Journal::query()->where('company_id', $company1->id)->where('code', 'DGEN')->firstOrFail();
            if ($dgen->default_account_id === null) {
                $dgen->update(['default_account_id' => $expenseGl->id]);
            } else {
                $existing++;
            }
            $journals['DGEN'] = $dgen;

            $dsal = Journal::query()->where('company_id', $company1->id)->where('code', 'DSAL')->first();
            if (! $dsal) {
                $dsal = Journal::query()->create([
                    'company_id'         => $company1->id,
                    'name'               => 'Demo Customer Invoices',
                    'code'               => 'DSAL',
                    'type'               => JournalType::SALE->value,
                    'currency_id'        => $company1->currency_id,
                    'default_account_id' => $arGl->id,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $journals['DSAL'] = $dsal;

            $dpur = Journal::query()->where('company_id', $company1->id)->where('code', 'DPUR')->first();
            if (! $dpur) {
                $dpur = Journal::query()->create([
                    'company_id'         => $company1->id,
                    'name'               => 'Demo Vendor Bills',
                    'code'               => 'DPUR',
                    'type'               => JournalType::PURCHASE->value,
                    'currency_id'        => $company1->currency_id,
                    'default_account_id' => $apGl->id,
                ]);
                $created++;
            } else {
                $existing++;
            }
            $journals['DPUR'] = $dpur;

            $this->recordStat('Journals', $created, $existing);

            return $journals;
        });
    }

    /**
     * Phase D: exchange rates, approved, including two USD rates on
     * different dates (the "historical rate is not overwritten" fixture).
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array<string, Currency>  $currencies
     */
    protected function seedExchangeRates(array $companies, array $currencies): void
    {
        DB::transaction(function () use ($companies, $currencies) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;

            $rates = [
                ['from' => 'USD', 'to' => 'PKR', 'date' => '2026-07-01', 'rate' => '278.500000000000000'],
                ['from' => 'USD', 'to' => 'PKR', 'date' => '2026-08-01', 'rate' => '281.250000000000000'],
                ['from' => 'AED', 'to' => 'PKR', 'date' => '2026-07-01', 'rate' => '75.800000000000000'],
            ];

            foreach ($rates as $r) {
                $source = $currencies[$r['from']];
                $target = $currencies[$r['to']];

                $exists = ExchangeRate::query()
                    ->where('company_id', $company1->id)
                    ->where('source_currency_id', $source->id)
                    ->where('target_currency_id', $target->id)
                    ->where('effective_date', $r['date'])
                    ->where('rate_type', ExchangeRateType::Transaction->value)
                    ->exists();

                if (! $exists) {
                    ExchangeRate::query()->create([
                        'company_id'         => $company1->id,
                        'source_currency_id' => $source->id,
                        'target_currency_id' => $target->id,
                        'effective_date'     => $r['date'],
                        'rate'               => $r['rate'],
                        'rate_type'          => ExchangeRateType::Transaction->value,
                        'source'             => ExchangeRateSource::Manual->value,
                        'approval_status'    => ExchangeRateApprovalStatus::Approved->value,
                        'approved_at'        => now(),
                    ]);
                    $created++;
                } else {
                    $existing++;
                }
            }

            $this->recordStat('Exchange Rates', $created, $existing);
        });
    }

    /**
     * Phase E: customers and vendors.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @return array<string, Partner>
     */
    protected function seedPartners(array $companies): array
    {
        return DB::transaction(function () use ($companies) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;
            $partners = [];

            $arGl = $this->glAccount($company1, self::AR_GL_CODE);
            $apGl = $this->glAccount($company1, self::AP_GL_CODE);

            for ($i = 1; $i <= 4; $i++) {
                $ref = sprintf('CUST-DEMO-%02d', $i);
                $partner = Partner::query()->where('company_id', $company1->id)->where('reference', $ref)->first();
                if (! $partner) {
                    $partner = Partner::query()->create([
                        'company_id'                      => $company1->id,
                        'reference'                       => $ref,
                        'name'                            => "Demo Customer {$i}",
                        'account_type'                    => 'company',
                        'sub_type'                        => 'customer',
                        'customer_rank'                   => 1,
                        'property_account_receivable_id'  => $arGl->id,
                    ]);
                    $created++;
                } else {
                    $existing++;
                }
                $partners[$ref] = $partner;
            }

            for ($i = 1; $i <= 5; $i++) {
                $ref = sprintf('VEND-DEMO-%02d', $i);
                $partner = Partner::query()->where('company_id', $company1->id)->where('reference', $ref)->first();
                if (! $partner) {
                    $partner = Partner::query()->create([
                        'company_id'                   => $company1->id,
                        'reference'                    => $ref,
                        'name'                         => "Demo Vendor {$i}",
                        'account_type'                 => 'company',
                        'sub_type'                     => 'supplier',
                        'supplier_rank'                => 1,
                        'property_account_payable_id'  => $apGl->id,
                    ]);
                    $created++;
                } else {
                    $existing++;
                }
                $partners[$ref] = $partner;
            }

            $this->recordStat('Partners', $created, $existing);

            return $partners;
        });
    }

    /**
     * Phase E: customer invoices and vendor bills, posted.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array<string, Journal>  $journals
     * @param  array<string, Partner>  $parties
     * @return array<string, Move>
     */
    protected function seedInvoicesAndBills(array $companies, array $journals, array $parties, array $users): array
    {
        return DB::transaction(function () use ($companies, $journals, $parties, $users) {
            $company1 = $companies['company1'];
            $accountant = $users['accountant'];
            $created = 0;
            $existing = 0;
            $docs = [];

            $revenueGl = $this->glAccount($company1, self::REVENUE_GL_CODE);
            $expenseGl = $this->glAccount($company1, self::GENERIC_EXPENSE_GL_CODE);

            $invoices = [
                ['ref' => 'INV-DEMO-1001', 'partner' => 'CUST-DEMO-01', 'amount' => 300000, 'date' => '2026-07-05', 'due' => '2026-08-04', 'booking' => 'BKG-1001', 'consolidated' => 'CON-1001'],
                ['ref' => 'INV-DEMO-1002', 'partner' => 'CUST-DEMO-02', 'amount' => 150000, 'date' => '2026-07-10', 'due' => '2026-08-09'],
                ['ref' => 'INV-DEMO-1003', 'partner' => 'CUST-DEMO-03', 'amount' => 425000, 'date' => '2026-06-01', 'due' => '2026-07-01'],
                ['ref' => 'INV-DEMO-1004', 'partner' => 'CUST-DEMO-04', 'amount' => 88000, 'date' => '2026-07-20', 'due' => '2026-08-19'],
            ];

            foreach ($invoices as $inv) {
                $move = Move::query()->where('company_id', $company1->id)->where('reference', $inv['ref'])->first();
                if (! $move) {
                    $partner = $parties[$inv['partner']];
                    $move = Move::query()->create([
                        'company_id'          => $company1->id,
                        'creator_id'          => $accountant->id,
                        'invoice_user_id'     => $accountant->id,
                        'journal_id'          => $journals['DSAL']->id,
                        'partner_id'          => $partner->id,
                        'currency_id'         => $company1->currency_id,
                        'move_type'           => MoveType::OUT_INVOICE->value,
                        'state'               => MoveState::DRAFT->value,
                        'date'                => $inv['date'],
                        'invoice_date'        => $inv['date'],
                        'invoice_date_due'    => $inv['due'],
                        'reference'           => $inv['ref'],
                        'booking_id'          => $inv['booking'] ?? null,
                        'consolidated_number' => $inv['consolidated'] ?? null,
                    ]);

                    MoveLine::query()->create([
                        'move_id'      => $move->id,
                        'account_id'   => $revenueGl->id,
                        'partner_id'   => $partner->id,
                        'currency_id'  => $company1->currency_id,
                        'display_type' => DisplayType::PRODUCT,
                        'name'         => "Logistics services — {$inv['ref']}",
                        'quantity'     => 1,
                        'price_unit'   => $inv['amount'],
                    ]);

                    $move = AccountFacade::confirmMove($move->fresh());
                    $created++;
                } else {
                    $move->update([
                        'creator_id'      => $accountant->id,
                        'invoice_user_id' => $accountant->id,
                    ]);
                    $existing++;
                }
                $docs[$inv['ref']] = $move;
            }

            $bills = [
                ['ref' => 'BILL-DEMO-2001', 'partner' => 'VEND-DEMO-01', 'amount' => 120000, 'date' => '2026-07-08', 'due' => '2026-08-07'],
                ['ref' => 'BILL-DEMO-2002', 'partner' => 'VEND-DEMO-02', 'amount' => 64000, 'date' => '2026-06-15', 'due' => '2026-07-15'],
            ];

            foreach ($bills as $bill) {
                $move = Move::query()->where('company_id', $company1->id)->where('reference', $bill['ref'])->first();
                if (! $move) {
                    $partner = $parties[$bill['partner']];
                    $move = Move::query()->create([
                        'company_id'       => $company1->id,
                        'creator_id'       => $accountant->id,
                        'journal_id'       => $journals['DPUR']->id,
                        'partner_id'       => $partner->id,
                        'currency_id'      => $company1->currency_id,
                        'move_type'        => MoveType::IN_INVOICE->value,
                        'state'            => MoveState::DRAFT->value,
                        'date'             => $bill['date'],
                        'invoice_date'     => $bill['date'],
                        'invoice_date_due' => $bill['due'],
                        'reference'        => $bill['ref'],
                    ]);

                    MoveLine::query()->create([
                        'move_id'      => $move->id,
                        'account_id'   => $expenseGl->id,
                        'partner_id'   => $partner->id,
                        'currency_id'  => $company1->currency_id,
                        'display_type' => DisplayType::PRODUCT,
                        'name'         => "Carrier payout — {$bill['ref']}",
                        'quantity'     => 1,
                        'price_unit'   => $bill['amount'],
                    ]);

                    $move = AccountFacade::confirmMove($move->fresh());
                    $created++;
                } else {
                    $move->update(['creator_id' => $accountant->id]);
                    $existing++;
                }
                $docs[$bill['ref']] = $move;
            }

            $this->recordStat('Invoices & Bills', $created, $existing);

            return $docs;
        });
    }

    /**
     * Phase F: import the HBL demo bank statement and leave the resulting
     * BankTransactionMapping rows in mixed review states.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array<string, Journal>  $journals
     * @param  array<string, Currency>  $currencies
     * @param  array{accountant: User, approver: User}  $users
     * @param  array<string, Move>  $docs
     */
    protected function seedBankStatement(array $companies, array $journals, array $currencies, array $users, array $docs): void
    {
        DB::transaction(function () use ($companies, $journals, $currencies, $users) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;

            $csvPath = base_path('plugins/webkul/accounting/database/fixtures/hbl-demo-statement.csv');
            if (! file_exists($csvPath)) {
                throw new \RuntimeException("HBL demo statement fixture not found at: {$csvPath}");
            }

            $existingStatement = BankStatement::query()
                ->where('company_id', $company1->id)
                ->where('bank_account_number', 'PK55DEMO0000000000000001')
                ->first();

            if ($existingStatement) {
                $this->recordStat('Bank Transaction Mappings', 0, 12);

                return;
            }

            $statement = app(BankStatementImportService::class)->import(
                path: $csvPath,
                company: $company1,
                journal: $journals['DBNK'],
                bankGlAccount: $this->glAccount($company1, self::BANK_GL_CODE),
                currency: $currencies['PKR'],
            );
            $created += 12;

            // Rows 1-3: obligation auto-matching against the open invoices/bill
            // (full/partial invoice match, partial bill match) — left Suggested.
            app(BankMatchingPriorityService::class)->run($company1->id);

            $mappings = BankTransactionMapping::query()
                ->where('company_id', $company1->id)
                ->whereHas('statementLine', fn ($q) => $q->where('statement_id', $statement->id))
                ->with('statementLine')
                ->get()
                ->sortBy(fn (BankTransactionMapping $m) => $m->statementLine->sort ?? $m->statementLine->id)
                ->values();

            // Rows 4-5, 8-11 (0-indexed 3,4,7,8,9,10): FS-tag resolved on
            // import; approve() auto-fills the offset GL/cash-flow category
            // from the tag and marks the mapping Approved (not posted).
            foreach ([3, 4, 7, 8, 9, 10] as $idx) {
                if (isset($mappings[$idx]) && $mappings[$idx]->review_status !== BankReviewStatus::Approved) {
                    app(BankMappingService::class)->approve($mappings[$idx], $users['approver'], false);
                }
            }

            // Rows 6-7 (0-indexed 5,6): the internal transfer pair. Both legs
            // sit on the SAME statement/bank account by design (§3.6), which
            // BankTransferMatchingService::detect() deliberately excludes
            // (it only pairs debit/credit lines across two DIFFERENT bank
            // accounts/statements, the real-world transfer shape). The match
            // is therefore created directly here, mirroring exactly what
            // detect() would persist for a genuine cross-account pair — see
            // the seeding report for why the pairing is illustrative only
            // and cannot be posted as-is.
            if (isset($mappings[5], $mappings[6])) {
                $outMapping = $mappings[5];
                $inMapping = $mappings[6];
                $outLine = $outMapping->statementLine;
                $inLine = $inMapping->statementLine;

                $match = BankTransferMatch::query()->firstOrCreate(
                    [
                        'company_id'                 => $company1->id,
                        'outgoing_statement_line_id' => $outLine->id,
                        'incoming_statement_line_id' => $inLine->id,
                    ],
                    [
                        'match_reference'       => 'TRF-DEMO-0715',
                        'amount'                => $outLine->debit,
                        'outgoing_currency_id'  => $outLine->original_currency_id,
                        'incoming_currency_id'  => $inLine->original_currency_id,
                        'outgoing_amount'       => $outLine->original_debit ?? $outLine->debit,
                        'incoming_amount'       => $inLine->original_credit ?? $inLine->credit,
                        'company_amount'        => $outLine->company_debit,
                        'confidence'            => 1,
                        'status'                => 'suggested',
                    ],
                );

                foreach ([
                    [$outMapping, BankPostingStatus::NotPosted],
                    [$inMapping, BankPostingStatus::MatchedDoNotPost],
                ] as [$mapping, $postingStatus]) {
                    if ($mapping->transfer_match_id === null) {
                        $mapping->update([
                            'offset_account_id'  => $mapping->bank_gl_account_id,
                            'transfer_match_id'  => $match->id,
                            'transaction_type'   => 'Internal transfer',
                            'review_status'      => BankReviewStatus::MatchedTransfer,
                            'posting_status'     => $postingStatus,
                            'cash_flow_category' => CashFlowCategory::Transfer->value,
                            'confidence'         => 1,
                        ]);
                    }
                }
            }

            // Row 12 (0-indexed 11): deliberately unmappable — no FS Tag, no
            // invoice/bill reference. Nothing in the automatic pipeline flags
            // it, so it is bumped to NeedsReview by hand.
            if (isset($mappings[11]) && $mappings[11]->review_status !== BankReviewStatus::NeedsReview) {
                $mappings[11]->update([
                    'review_status'  => BankReviewStatus::NeedsReview,
                    'posting_status' => BankPostingStatus::NotPosted,
                ]);
            }

            $this->recordStat('Bank Transaction Mappings', $created, $existing);
        });
    }

    /**
     * Phase G: the bank_statement ImportProfile + mappings, and a
     * mark_non_critical BusinessRule to demonstrate warn_continue.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array{accountant: User, approver: User}  $users
     */
    protected function seedImportProfiles(array $companies, array $users): void
    {
        DB::transaction(function () use ($companies, $users) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;

            $jsonPath = base_path('plugins/webkul/accounting/database/fixtures/bank-statement-profile.json');
            if (! file_exists($jsonPath)) {
                throw new \RuntimeException("Bank statement import profile fixture not found at: {$jsonPath}");
            }
            $definition = json_decode(file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);
            $profileData = $definition['profile'];

            $profile = ImportProfile::query()
                ->where('company_id', $company1->id)
                ->where('name', $profileData['name'])
                ->where('entity_type', 'bank_statement')
                ->first();

            if (! $profile) {
                $profile = ImportProfile::query()->create([
                    'company_id'     => $company1->id,
                    'owner_id'       => $users['accountant']->id,
                    'name'           => $profileData['name'],
                    'entity_type'    => 'bank_statement',
                    'file_type'      => $profileData['file_type'],
                    'sheet_name'     => $profileData['sheet_name'] ?? null,
                    'header_row'     => $profileData['header_row'],
                    'data_start_row' => $profileData['data_start_row'],
                    'skip_rows'      => $profileData['skip_rows'],
                    'blank_row_rule' => $profileData['blank_row_rule'],
                    'failure_policy' => $profileData['failure_policy'],
                    'stop_rule'      => $profileData['stop_rule'] ?? null,
                    'delimiter'      => $profileData['delimiter'],
                    'encoding'       => $profileData['encoding'],
                    'version'        => 1,
                    'is_active'      => true,
                    'activated_at'   => now(),
                ]);

                foreach ($definition['mappings'] as $mapping) {
                    ImportProfileMapping::query()->create([
                        'profile_id'       => $profile->id,
                        'position'         => $mapping['position'],
                        'source_header'    => $mapping['source_header'],
                        'source_position'  => $mapping['source_position'] ?? null,
                        'source_aliases'   => $mapping['source_aliases'] ?? [],
                        'target_field'     => $mapping['target_field'],
                        'transformations'  => $mapping['transformations'] ?? [],
                        'validation_rules' => $mapping['validation_rules'] ?? [],
                        'is_required'      => $mapping['is_required'] ?? false,
                    ]);
                }
                $created++;
            } else {
                $existing++;
            }

            $rule = BusinessRule::query()
                ->where('company_id', $company1->id)
                ->where('name', 'Warn on Missing Counterparty')
                ->where('entity_type', 'bank_statement')
                ->first();

            if (! $rule) {
                BusinessRule::query()->create([
                    'company_id'      => $company1->id,
                    'profile_id'      => $profile->id,
                    'creator_id'      => $users['accountant']->id,
                    'name'            => 'Warn on Missing Counterparty',
                    'entity_type'     => 'bank_statement',
                    'priority'        => 100,
                    'conditions'      => [['field' => 'counterparty', 'operator' => 'blank']],
                    'actions'         => [['type' => 'mark_non_critical', 'field' => 'counterparty']],
                    'stop_processing' => false,
                    'is_active'       => true,
                ]);
                $created++;
            } else {
                $existing++;
            }

            $this->recordStat('Import Profiles & Rules', $created, $existing);
        });
    }

    /**
     * Phase H: amount-gated approval workflows for bank_transaction_mapping
     * and manual_adjustment.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array{accountant: User, approver: User}  $users
     */
    protected function seedApprovalWorkflows(array $companies, array $users): void
    {
        DB::transaction(function () use ($companies, $users) {
            $company1 = $companies['company1'];
            $approver = $users['approver'];
            $created = 0;
            $existing = 0;

            $bankWorkflow = ApprovalWorkflow::query()
                ->where('company_id', $company1->id)
                ->where('request_type', 'bank_transaction_mapping')
                ->first();

            if (! $bankWorkflow) {
                $bankWorkflow = ApprovalWorkflow::query()->create([
                    'company_id'     => $company1->id,
                    'name'           => 'High-Value Bank Transaction Mapping Approval',
                    'request_type'   => 'bank_transaction_mapping',
                    'minimum_amount' => 100000,
                    'is_active'      => true,
                ]);
                ApprovalStep::query()->create([
                    'workflow_id'        => $bankWorkflow->id,
                    'sequence'           => 1,
                    'name'               => 'Manager Approval',
                    'approver_user_id'   => $approver->id,
                    'required_approvals' => 1,
                ]);
                $created++;
            } else {
                $existing++;
            }

            $adjustmentWorkflow = ApprovalWorkflow::query()
                ->where('company_id', $company1->id)
                ->where('request_type', 'manual_adjustment')
                ->first();

            if (! $adjustmentWorkflow) {
                $adjustmentWorkflow = ApprovalWorkflow::query()->create([
                    'company_id'   => $company1->id,
                    'name'         => 'Manual Adjustment Approval',
                    'request_type' => 'manual_adjustment',
                    'is_active'    => true,
                ]);
                ApprovalStep::query()->create([
                    'workflow_id'        => $adjustmentWorkflow->id,
                    'sequence'           => 1,
                    'name'               => 'Manager Approval',
                    'approver_user_id'   => $approver->id,
                    'required_approvals' => 1,
                ]);
                $created++;
            } else {
                $existing++;
            }

            $this->recordStat('Approval Workflows', $created, $existing);
        });
    }

    /**
     * Phase I: one Draft manual adjustment for the tester to walk through
     * submit → approve → post.
     *
     * @param  array{company1: Company, company2: Company}  $companies
     * @param  array{accountant: User, approver: User}  $users
     */
    protected function seedManualAdjustment(array $companies, array $users): void
    {
        DB::transaction(function () use ($companies, $users) {
            $company1 = $companies['company1'];
            $created = 0;
            $existing = 0;

            $adjustment = ManualAdjustment::query()
                ->where('company_id', $company1->id)
                ->where('description', 'Demo year-end audit adjustment')
                ->first();

            if (! $adjustment) {
                ManualAdjustment::query()->create([
                    'company_id'            => $company1->id,
                    'date'                  => '2026-07-31',
                    'debit_account_id'      => $this->glAccount($company1, self::GENERIC_EXPENSE_GL_CODE)->id,
                    'credit_account_id'     => $this->glAccount($company1, self::BANK_GL_CODE)->id,
                    'amount'                => '20000.0000',
                    'description'           => 'Demo year-end audit adjustment',
                    'approval_status'       => ManualAdjustmentStatus::Draft->value,
                    'source_classification' => 'demo_seed',
                    'creator_id'            => $users['accountant']->id,
                ]);
                $created++;
            } else {
                $existing++;
            }

            $this->recordStat('Manual Adjustments', $created, $existing);
        });
    }

    /**
     * Resolve a postable, company-owned GL account by code. Used throughout
     * instead of "first account of type X" so every phase points at a
     * specific, semantically meaningful GL rather than whichever leaf
     * happens to sort first.
     */
    protected function glAccount(Company $company, string $code): Account
    {
        return Account::query()
            ->where('code', $code)
            ->where('is_group', false)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->firstOrFail();
    }

    protected function recordStat(string $entity, int $created, int $existing): void
    {
        if (isset($this->stats[$entity])) {
            $this->stats[$entity]['created'] += $created;
            $this->stats[$entity]['existing'] += $existing;

            return;
        }

        $this->stats[$entity] = ['created' => $created, 'existing' => $existing];
    }

    protected function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $rows = [];
        foreach ($this->stats as $entity => $data) {
            $rows[] = [$entity, $data['created'], $data['existing'], $data['created'] + $data['existing']];
        }

        $this->command->newLine();
        $this->command->info('=== Aureus ERP Accounting Demo Dataset Seed Summary ===');
        $this->command->table(['Entity', 'Created', 'Existing', 'Total'], $rows);
        $this->command->newLine();
        $this->command->line('Demo logins (password: "password"):');
        $this->command->line('  Preparer: demo.accountant@aureus.test');
        $this->command->line('  Approver: demo.approver@aureus.test');
        $this->command->line('Default company: Truck It In (Demo)');
    }
}
