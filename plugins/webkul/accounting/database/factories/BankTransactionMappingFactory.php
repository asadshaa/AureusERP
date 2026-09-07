<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<BankTransactionMapping>
 */
class BankTransactionMappingFactory extends Factory
{
    protected $model = BankTransactionMapping::class;

    public function definition(): array
    {
        return [
            'company_id'           => Company::factory(),
            'statement_line_id'    => BankStatementLine::factory(),
            'bank_gl_account_id'   => Account::factory(),
            'offset_account_id'    => null,
            'original_currency_id' => Currency::factory(),
            'company_currency_id'  => Currency::factory(),
            'review_status'        => BankReviewStatus::Unmapped,
            'posting_status'       => BankPostingStatus::NotPosted,
            'confidence'           => '0.0000',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'review_status' => BankReviewStatus::Approved,
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'review_status' => BankReviewStatus::NeedsReview,
        ]);
    }
}
