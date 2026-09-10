<?php

namespace Webkul\Accounting\Support;

final class AccountingPermissions
{
    public const ImportBankStatementPage = 'page_accounting_import_bank_statement';

    public const BankStatements = 'accounting_view_bank_statements';

    public const BankTransactions = 'accounting_view_bank_transactions';

    public const ReviewBankTransactions = 'accounting_review_bank_transactions';

    public const ManageBankMappingRules = 'accounting_manage_bank_mapping_rules';

    public const ManageManualAdjustments = 'accounting_manage_manual_adjustments';

    public const ManageImportProfiles = 'accounting_manage_import_profiles';

    public const RunConfiguredImports = 'accounting_run_configured_imports';

    public const ManageFsTags = 'accounting_manage_fs_tags';

    public const ManageBusinessRules = 'accounting_manage_business_rules';

    public const ManagePartyClassifications = 'accounting_manage_party_classifications';

    public const ManageCurrencies = 'accounting_manage_currencies';

    public const ManageCompanyCurrencies = 'accounting_manage_company_currencies';

    public const ManageExchangeRates = 'accounting_manage_exchange_rates';

    public const ApproveExchangeRates = 'accounting_approve_exchange_rates';

    public const ViewMissingRates = 'accounting_view_missing_rates';

    public const CreateBankAccount = 'accounting_create_bank_gl';

    public const CreateBankJournal = 'accounting_create_bank_journal';

    public const CreateOffsetAccount = 'accounting_create_offset_gl';

    public const GenerateJournal = 'accounting_generate_journal';

    public const ApproveJournal = 'accounting_approve_journal';

    public const PostJournal = 'accounting_post_journal';

    public const RunFxRevaluation = 'accounting_run_fx_revaluation';

    public const ViewMultiCurrencyReports = 'accounting_view_multi_currency_reports';

    public const ViewAccountingChecks = 'page_accounting_accounting_checks';

    public const CompanyCurrencySettingsPage = 'page_accounting_company_currency_settings';

    public const ExchangeRatesPage = 'page_accounting_exchange_rates';

    public const MissingRatesPage = 'page_accounting_missing_exchange_rates';

    public const FxRevaluationPage = 'page_accounting_fx_revaluation';

    public const ViewDocuments = 'accounting_view_documents';

    public const ManageDocuments = 'accounting_manage_documents';

    public const DownloadDocuments = 'accounting_download_documents';

    public const DeleteDocuments = 'accounting_delete_documents';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ImportBankStatementPage,
            self::BankStatements,
            self::BankTransactions,
            self::ReviewBankTransactions,
            self::ManageBankMappingRules,
            self::ManageManualAdjustments,
            self::ManageImportProfiles,
            self::RunConfiguredImports,
            self::ManageFsTags,
            self::ManageBusinessRules,
            self::ManagePartyClassifications,
            self::ManageCurrencies,
            self::ManageCompanyCurrencies,
            self::ManageExchangeRates,
            self::ApproveExchangeRates,
            self::ViewMissingRates,
            self::CreateBankAccount,
            self::CreateBankJournal,
            self::CreateOffsetAccount,
            self::GenerateJournal,
            self::ApproveJournal,
            self::PostJournal,
            self::RunFxRevaluation,
            self::ViewMultiCurrencyReports,
            self::ViewAccountingChecks,
            self::CompanyCurrencySettingsPage,
            self::ExchangeRatesPage,
            self::MissingRatesPage,
            self::FxRevaluationPage,
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            self::DeleteDocuments,
            'page_accounting_overview',
            'page_accounting_manage_taxes',
            'page_accounting_manage_products',
            'page_accounting_manage_default_accounts',
            'page_accounting_manage_customer_invoice',
            'page_accounting_import_chart_of_accounts',
            'page_accounting_imported_chart_of_accounts',
            'page_accounting_aged_payable',
            'page_accounting_aged_receivable',
            'page_accounting_direct_cash_flow',
            'page_accounting_trial_balance',
            'page_accounting_balance_sheet',
            'page_accounting_profit_loss',
            'page_accounting_general_ledger',
            'page_accounting_financial_reports',
            'page_accounting_external_providers',
            'page_accounting_partner_ledger',
            'page_accounting_partner_analytics',
            'page_accounting_report_mapping_review',
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'update_accounting_bank_transaction_mapping',
            'view_any_accounting_bank_mapping_rule',
            'view_accounting_bank_mapping_rule',
            'create_accounting_bank_mapping_rule',
            'update_accounting_bank_mapping_rule',
            'delete_accounting_bank_mapping_rule',
            'view_any_accounting_manual_adjustment',
            'view_accounting_manual_adjustment',
            'create_accounting_manual_adjustment',
            'update_accounting_manual_adjustment',
            'view_any_accounting_exchange_rate',
            'view_accounting_exchange_rate',
            'create_accounting_exchange_rate',
            'update_accounting_exchange_rate',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'create_accounting_invoice',
            'update_accounting_invoice',
            'delete_accounting_invoice',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'create_accounting_bill',
            'update_accounting_bill',
            'delete_accounting_bill',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function accountant(): array
    {
        return array_values(array_diff(self::all(), [
            self::ManageCurrencies,
            self::ManageCompanyCurrencies,
            self::ApproveExchangeRates,
            self::ApproveJournal,
            self::PostJournal,
            self::RunFxRevaluation,
            self::FxRevaluationPage,
            self::DeleteDocuments,
        ]));
    }
}
