<?php

namespace Webkul\Accounting\Services\Import;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Tax;
use Webkul\Accounting\Enums\ImportFailurePolicy;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportProfileMapping;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Models\ImportSourceRow;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\Currency;

final class ImportPreviewService
{
    /**
     * Fields a Warn-and-Continue BusinessRule action is never allowed to downgrade from
     * error to warning, regardless of configuration — these are hard accounting-integrity
     * checks (unknown/inactive/wrong-company GL or FS Tag, bad currency, unbalanced
     * amounts), and "warn and continue" must never bypass them (see the four-policy spec).
     */
    private const HARD_INTEGRITY_FIELDS = [
        'gl_code', 'offset_gl_code', 'bank_gl_code', 'debit_gl_code', 'credit_gl_code',
        'fs_tag', 'currency', 'debit', 'credit', 'amount_total', 'amount_untaxed', 'amount_tax',
    ];

    public function __construct(
        private readonly TabularFileReader $reader,
        private readonly ImportTransformationEngine $transformations,
        private readonly ConditionalRuleEngine $rules,
        private readonly ImportEntityRegistry $entities,
        private readonly FsTagService $fsTags,
    ) {}

    /** @return array<int, array{severity: string, field: string, message: string}> */
    private function validateFsTagReference(int $companyId, string $rawCode): array
    {
        $tagCode = trim($rawCode);
        $tagInCompany = $this->fsTags->resolve($companyId, $tagCode, activeOnly: false);

        if (! $tagInCompany) {
            $existsInAnotherCompany = $this->fsTags->existsForAnyCompany($tagCode);

            return [[
                'severity' => 'error',
                'field'    => 'fs_tag',
                'message'  => $existsInAnotherCompany
                    ? 'The FS Tag belongs to another company.'
                    : 'The FS Tag does not exist in this company.',
            ]];
        }

        if (! $tagInCompany->is_active) {
            return [[
                'severity' => 'error',
                'field'    => 'fs_tag',
                'message'  => 'The FS Tag is inactive in this company.',
            ]];
        }

        return [];
    }

    public function preview(ImportProfile $profile, string $path, string $originalFilename, ?int $userId = null): ImportRun
    {
        if (! $profile->is_active) {
            throw new \RuntimeException('Only an active import profile can be used.');
        }
        if (! $this->entities->supports($profile->entity_type)) {
            throw new \RuntimeException("Unsupported import entity [{$profile->entity_type}].");
        }

        $profile->loadMissing('mappings');
        $file = $this->reader->read($path, $profile);
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException('The import file could not be hashed.');
        }

        $effectiveRules = BusinessRule::effective($profile->company_id, $profile->entity_type)
            ->where(fn ($query) => $query->whereNull('profile_id')->orWhere('profile_id', $profile->id))
            ->get();

        return DB::transaction(function () use ($profile, $file, $hash, $path, $originalFilename, $userId, $effectiveRules): ImportRun {
            $run = ImportRun::query()->create([
                'company_id'        => $profile->company_id,
                'profile_id'        => $profile->id,
                'imported_by_id'    => $userId,
                'reference'         => 'IMP-'.Str::upper(Str::random(14)),
                'status'            => 'previewed',
                'original_filename' => basename($originalFilename),
                'file_hash'         => $hash,
                'source_sheet'      => $file['sheet'],
                'profile_version'   => $profile->version,
            ]);

            $preparedRows = [];
            foreach ($file['rows'] as $source) {
                [$values, $messages] = $this->mapAndValidate($profile, $file['headers'], $source['values'], $effectiveRules);
                $status = collect($messages)->contains(fn (array $message): bool => $message['severity'] === 'error')
                    ? 'error'
                    : (collect($messages)->contains(fn (array $message): bool => $message['severity'] === 'warning') ? 'warning' : 'pass');

                $preparedRows[] = [
                    'source'      => $source,
                    'values'      => $values,
                    'messages'    => $messages,
                    'status'      => $status,
                    'fingerprint' => $this->fingerprint($profile->entity_type, $values),
                ];
            }

            $fingerprints = collect($preparedRows)->pluck('fingerprint')->filter()->unique()->values();
            $existingByFingerprint = $fingerprints->isEmpty()
                ? collect()
                : ImportSourceRow::query()
                    ->where('company_id', $profile->company_id)
                    ->whereNotNull('canonical_id')
                    ->whereIn('fingerprint', $fingerprints)
                    ->whereHas('run.profile', fn ($query) => $query->where('entity_type', $profile->entity_type))
                    ->orderBy('id')
                    ->get()
                    ->keyBy('fingerprint');
            $counts = ['pass' => 0, 'warning' => 0, 'error' => 0, 'duplicate' => 0];
            $seenInRun = [];

            foreach ($preparedRows as $prepared) {
                $source = $prepared['source'];
                $status = $prepared['status'];
                $messages = $prepared['messages'];
                $fingerprint = $prepared['fingerprint'];
                $duplicateOf = $fingerprint === null ? null : ($existingByFingerprint[$fingerprint] ?? $seenInRun[$fingerprint] ?? null);

                if ($status !== 'error' && $duplicateOf !== null) {
                    $status = 'duplicate';
                    $messages[] = [
                        'severity' => 'warning',
                        'message'  => 'This row matches a previously imported or earlier row and will not be imported again.',
                    ];
                }

                $counts[$status]++;

                $created = $run->sourceRows()->create([
                    'company_id'                 => $profile->company_id,
                    'source_row_number'          => $source['row_number'],
                    'status'                     => $status,
                    'fingerprint'                => $fingerprint,
                    'duplicate_of_source_row_id' => $duplicateOf?->id,
                    'raw_values'                 => array_combine($file['headers'], array_pad($source['values'], count($file['headers']), null)),
                    'transformed_values'         => $prepared['values'],
                    'messages'                   => $messages,
                ]);

                if ($fingerprint !== null && self::isEligibleForDuplicateRegistration($status) && ! isset($seenInRun[$fingerprint])) {
                    $seenInRun[$fingerprint] = $created;
                }
            }

            $run->update([
                'total_rows'     => count($file['rows']),
                'passed_rows'    => $counts['pass'],
                'warning_rows'   => $counts['warning'],
                'failed_rows'    => $counts['error'],
                'duplicate_rows' => $counts['duplicate'],
                'summary'        => [
                    'headers'            => $file['headers'],
                    'profile_name'       => $profile->name,
                    'entity_type'        => $profile->entity_type,
                    'profile_version'    => $profile->version,
                    'staged_path'        => $path,
                    'failure_policy'     => $profile->failure_policy,
                    'failure_categories' => collect($preparedRows)
                        ->flatMap(fn (array $prepared): array => $prepared['messages'])
                        ->filter(fn (array $message): bool => $message['severity'] === 'error')
                        ->countBy(fn (array $message): string => (string) ($message['field'] ?? 'other'))
                        ->all(),
                ],
            ]);

            return $run->fresh(['sourceRows']);
        });
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $sourceValues
     * @param  Collection<int, BusinessRule>  $effectiveRules
     * @return array{0: array<string, mixed>, 1: array<int, array{severity: string, field?: string, message: string, rule_id?: int}>}
     */
    private function mapAndValidate(ImportProfile $profile, array $headers, array $sourceValues, $effectiveRules): array
    {
        $messages = [];
        $values = [];
        $normalizedHeaders = array_map($this->normalizeHeader(...), $headers);

        foreach ($profile->mappings as $mapping) {
            $raw = $this->sourceValue($mapping, $normalizedHeaders, $sourceValues);
            try {
                $values[$mapping->target_field] = $this->transformations->transform($raw, (array) $mapping->transformations, $values);
            } catch (Throwable $exception) {
                $values[$mapping->target_field] = $raw;
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => $exception->getMessage()];
            }

            if ($mapping->is_required && (($values[$mapping->target_field] ?? null) === null || ($values[$mapping->target_field] ?? '') === '')) {
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => 'A required value is missing.'];
            }

            $messages = [...$messages, ...$this->validateMappedValue($mapping, $values[$mapping->target_field] ?? null)];
        }

        try {
            $result = $this->rules->apply($values, $effectiveRules);
            $values = $result['values'];
            foreach ($result['applied_rule_ids'] as $ruleId) {
                $messages[] = ['severity' => 'warning', 'message' => 'A configured business rule changed this row.', 'rule_id' => $ruleId];
            }
        } catch (Throwable $exception) {
            $messages[] = ['severity' => 'error', 'message' => $exception->getMessage()];
        }

        foreach ($this->entities->requiredFields($profile->entity_type) as $required) {
            if (($values[$required] ?? null) === null || ($values[$required] ?? '') === '') {
                $messages[] = ['severity' => 'error', 'field' => $required, 'message' => 'This canonical field is required.'];
            }
        }

        $messages = [...$messages, ...$this->validateReferences($profile, $values)];

        if (ImportFailurePolicy::fromValue($profile->failure_policy) === ImportFailurePolicy::WarnContinue) {
            $messages = $this->downgradeNonCriticalErrors($messages, $values, $effectiveRules);
        }

        return [$values, $messages];
    }

    /**
     * Under the Warn-and-Continue failure policy only, downgrade an 'error' message to
     * 'warning' (so the row imports instead of being excluded) when an active BusinessRule
     * explicitly marks that field non-critical for this row via a `mark_non_critical`
     * action — never for a hard accounting-integrity field (see HARD_INTEGRITY_FIELDS).
     *
     * @param  array<int, array{severity: string, field?: string, message: string}>  $messages
     * @param  array<string, mixed>  $values
     * @param  Collection<int, BusinessRule>  $effectiveRules
     * @return array<int, array{severity: string, field?: string, message: string}>
     */
    private function downgradeNonCriticalErrors(array $messages, array $values, $effectiveRules): array
    {
        $nonCriticalFields = $effectiveRules
            ->filter(fn (BusinessRule $rule): bool => $this->rules->matches($values, (array) $rule->conditions))
            ->flatMap(fn (BusinessRule $rule) => collect((array) $rule->actions)
                ->filter(fn ($action): bool => (string) ($action['type'] ?? '') === 'mark_non_critical')
                ->pluck('field'))
            ->filter()
            ->unique()
            ->all();

        if ($nonCriticalFields === []) {
            return $messages;
        }

        return array_map(function (array $message) use ($nonCriticalFields): array {
            $field = $message['field'] ?? null;
            if (
                $message['severity'] === 'error'
                && $field !== null
                && in_array($field, $nonCriticalFields, true)
                && ! in_array($field, self::HARD_INTEGRITY_FIELDS, true)
            ) {
                $message['severity'] = 'warning';
            }

            return $message;
        }, $messages);
    }

    /** @param array<int, string> $normalizedHeaders @param array<int, mixed> $sourceValues */
    private function sourceValue(ImportProfileMapping $mapping, array $normalizedHeaders, array $sourceValues): mixed
    {
        if ($mapping->source_position !== null) {
            return $sourceValues[max(0, $mapping->source_position - 1)] ?? null;
        }

        $candidates = array_filter([$mapping->source_header, ...(array) $mapping->source_aliases]);
        foreach ($candidates as $candidate) {
            $index = array_search($this->normalizeHeader((string) $candidate), $normalizedHeaders, true);
            if ($index !== false) {
                return $sourceValues[$index] ?? null;
            }
        }

        return null;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)->squish()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    /** @param array<string, mixed> $values @return array<int, array{severity: string, field?: string, message: string}> */
    private function validateReferences(ImportProfile $profile, array $values): array
    {
        $messages = [];
        if (! empty($values['currency']) && ! Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['currency'])])->exists()) {
            $messages[] = ['severity' => 'error', 'field' => 'currency', 'message' => 'The currency code does not exist.'];
        }

        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim'], true)) {
            $partnerQuery = Partner::query()->where('company_id', $profile->company_id);
            if (filled($values['partner_reference'] ?? null)) {
                $partnerQuery->where('reference', trim((string) $values['partner_reference']));
            } else {
                if (filled($values['customer_name'] ?? null)) {
                    $partnerQuery->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $values['customer_name']))]);
                }
                if (filled($values['customer_email'] ?? null)) {
                    $partnerQuery->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $values['customer_email']))]);
                }
            }

            if (! filled($values['partner_reference'] ?? null)
                && ! filled($values['customer_name'] ?? null)
                && ! filled($values['customer_email'] ?? null)) {
                $messages[] = ['severity' => 'error', 'field' => 'partner_reference', 'message' => 'A party reference, name or email is required.'];
            } else {
                $partnerCount = $partnerQuery->count();
                if ($partnerCount === 0) {
                    $messages[] = [
                        'severity' => 'error',
                        'field'    => 'partner_reference',
                        'message'  => filled($values['partner_reference'] ?? null)
                            ? 'The party reference does not exist in this company.'
                            : 'The party does not exist in this company.',
                    ];
                } elseif ($partnerCount > 1) {
                    $messages[] = ['severity' => 'error', 'field' => 'partner_reference', 'message' => 'The party identity is ambiguous in this company.'];
                }
            }
        }

        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim', 'miscellaneous'], true) && ! empty($values['journal_code'])) {
            $exists = Journal::query()
                ->where('company_id', $profile->company_id)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
                ->exists();
            if (! $exists) {
                $messages[] = ['severity' => 'error', 'field' => 'journal_code', 'message' => 'The journal code does not exist in this company.'];
            }
        }
        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim', 'miscellaneous'], true)) {
            foreach (['debit_gl_code', 'credit_gl_code'] as $field) {
                if (! empty($values[$field]) && ! Account::query()->postable()->where('deprecated', false)
                    ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values[$field])])
                    ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))->exists()) {
                    $messages[] = ['severity' => 'error', 'field' => $field, 'message' => 'The GL code is not an active postable account in this company.'];
                }
            }

            if (! empty($values['payment_term']) && ! PaymentTerm::query()
                ->where('company_id', $profile->company_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $values['payment_term']))])
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'payment_term', 'message' => 'The payment term does not exist in this company.'];
            }

            try {
                $taxPercent = BigDecimal::of((string) ($values['tax_percent'] ?? '0'));
                if ($taxPercent->isNegative() || $taxPercent->isGreaterThan('100')) {
                    $messages[] = ['severity' => 'error', 'field' => 'tax_percent', 'message' => 'Tax percentage must be between 0 and 100.'];
                } elseif ($taxPercent->isPositive() && ! Tax::query()
                    ->where('company_id', $profile->company_id)
                    ->where('is_active', true)
                    ->where('amount', $taxPercent->__toString())
                    ->where('type_tax_use', $profile->entity_type === 'bill' ? TypeTaxUse::PURCHASE : TypeTaxUse::SALE)
                    ->exists()) {
                    $messages[] = ['severity' => 'error', 'field' => 'tax_percent', 'message' => 'No active company tax matches this percentage and document type.'];
                }
            } catch (Throwable) {
                $messages[] = ['severity' => 'error', 'field' => 'tax_percent', 'message' => 'Tax percentage must be a valid number.'];
            }
        }

        if ($profile->entity_type === 'bank_statement') {
            if (! empty($values['journal_code']) && ! Journal::query()
                ->where('company_id', $profile->company_id)
                ->where('type', JournalType::BANK)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'journal_code', 'message' => 'The bank journal code does not exist in this company.'];
            }
            if (! empty($values['bank_gl_code']) && ! Account::query()
                ->postable()->where('deprecated', false)->where('account_type', AccountType::ASSET_CASH)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['bank_gl_code'])])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'bank_gl_code', 'message' => 'The Bank GL code is not an active company-owned Bank/Cash account.'];
            }

            if (! empty($values['offset_gl_code']) && ! Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['offset_gl_code'])])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'offset_gl_code', 'message' => 'The Offset GL code is not an active postable account in this company.'];
            }

            if (! empty($values['fs_tag'])) {
                $messages = [...$messages, ...$this->validateFsTagReference($profile->company_id, (string) $values['fs_tag'])];
            }
        }

        if ($profile->entity_type === 'fs_tag') {
            $code = mb_strtoupper(trim((string) ($values['code'] ?? '')));
            $name = mb_strtolower(trim((string) ($values['name'] ?? '')));

            if ($code !== '' && FsTag::query()->where('company_id', $profile->company_id)->where('code', $code)->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'code', 'message' => 'The FS Tag code already exists in this company.'];
            }
            if ($name !== '' && FsTag::query()->where('company_id', $profile->company_id)->where('normalized_name', $name)->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'name', 'message' => 'The FS Tag name already exists in this company.'];
            }
            if (! empty($values['gl_code']) && ! Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $values['gl_code']))])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'gl_code', 'message' => 'The linked GL code is not an active postable account in this company.'];
            }
        }

        if (in_array($profile->entity_type, ['opening_balance', 'journal_entry'], true)) {
            if (! empty($values['journal_code']) && ! Journal::query()
                ->where('company_id', $profile->company_id)
                ->where('type', JournalType::GENERAL)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'journal_code', 'message' => 'The general journal code does not exist in this company.'];
            }

            if (! empty($values['gl_code']) && ! Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['gl_code'])])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'gl_code', 'message' => 'The GL code is not an active postable account in this company.'];
            }

            try {
                $debit = BigDecimal::of((string) ($values['debit'] ?? '0'));
                $credit = BigDecimal::of((string) ($values['credit'] ?? '0'));
                $bothPositive = $debit->isPositive() && $credit->isPositive();
                $bothZero = $debit->isZero() && $credit->isZero();
                if ($debit->isNegative() || $credit->isNegative() || $bothPositive || ($bothZero && $profile->entity_type === 'journal_entry')) {
                    $messages[] = ['severity' => 'error', 'field' => 'debit', 'message' => 'Each row must contain one positive debit or credit amount, never both.'];
                }
            } catch (Throwable) {
                $messages[] = ['severity' => 'error', 'field' => 'debit', 'message' => 'Debit and credit must be valid numbers.'];
            }

            if (in_array($profile->entity_type, ['opening_balance', 'journal_entry'], true) && ! empty($values['fs_tag'])) {
                $messages = [...$messages, ...$this->validateFsTagReference($profile->company_id, (string) $values['fs_tag'])];
            }
        }

        return $messages;
    }

    /** @return array<int, array{severity: string, field: string, message: string}> */
    private function validateMappedValue(ImportProfileMapping $mapping, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $messages = [];
        foreach ((array) $mapping->validation_rules as $rule) {
            $rule = (string) $rule;
            $valid = match ($rule) {
                'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'date'    => $this->isDate($value),
                'numeric' => $this->isDecimal($value),
                'boolean' => is_bool($value) || in_array(mb_strtolower((string) $value), ['0', '1', 'true', 'false', 'yes', 'no'], true),
                default   => false,
            };
            if (! $valid) {
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => "The value failed the {$rule} check."];
            }
        }

        return $messages;
    }

    private function isDate(mixed $value): bool
    {
        try {
            CarbonImmutable::parse((string) $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isDecimal(mixed $value): bool
    {
        try {
            BigDecimal::of((string) $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $values */
    private function fingerprint(string $entityType, array $values): ?string
    {
        $keys = match ($entityType) {
            'bank_statement' => [
                'bank_account_number', 'currency', 'date', 'value_date', 'reference', 'description', 'debit', 'credit',
            ],
            'vendor', 'customer'                        => ['reference', 'name', 'email', 'tax_id'],
            'employee'                                  => ['identification_id', 'work_email', 'name'],
            'fs_tag'                                    => ['code'],
            'invoice', 'bill', 'claim', 'miscellaneous' => [
                'reference', 'partner_reference', 'customer_name', 'customer_email', 'date', 'amount_total', 'currency',
            ],
            'opening_balance' => ['gl_code', 'date', 'debit', 'credit', 'currency'],
            'journal_entry'   => ['journal_entry_id', 'gl_code', 'date', 'debit', 'credit', 'currency'],
            default           => array_keys($values),
        };

        $identity = [];
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            $identity[$key] = is_string($value)
                ? mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value))
                : $value;
        }

        if (collect($identity)->every(fn (mixed $value): bool => $value === null || $value === '')) {
            return null;
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function isEligibleForDuplicateRegistration(string $status): bool
    {
        return in_array($status, ['pass', 'warning'], true);
    }
}
