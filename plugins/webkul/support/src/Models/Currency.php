<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Support\Database\Factories\CurrencyFactory;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'symbol',
        'iso_numeric',
        'decimal_places',
        'full_name',
        'rounding',
        'active',
        'is_iso_fiat',
        'display_order',
    ];

    protected $casts = [
        'active'        => 'boolean',
        'is_iso_fiat'   => 'boolean',
        'display_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CurrencyRate::class);
    }

    public function enabledCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'accounting_company_currencies')
            ->withPivot(['transaction_enabled', 'reporting_enabled'])
            ->withTimestamps();
    }

    public function getDisplayNameAttribute(): string
    {
        $code = $this->code ?: $this->name;

        return trim("{$code} - {$this->full_name}");
    }

    /**
     * @param  bool  $strict  When true, throws instead of silently falling back to a
     *                        1:1 rate when no CurrencyRate record exists for this pair.
     *                        Left false by default so existing draft/preview call sites
     *                        keep working unchanged; anything that turns this amount
     *                        into a real, ledger-affecting journal entry should pass
     *                        true instead of trusting a rate nobody actually configured.
     */
    public function convert(float|int $fromAmount, Currency $toCurrency, ?Company $company = null, $date = null, bool $round = true, bool $strict = false): float
    {
        $base = $this ?? $toCurrency;

        $toCurrency = $toCurrency ?? $this;

        if ($fromAmount) {
            $rate = $this->getConversionRate($base, $toCurrency, $company, $date, $strict);

            $toAmount = $fromAmount * $rate;
        } else {
            return 0.0;
        }

        return $round ? $toCurrency->round($toAmount) : $toAmount;
    }

    /**
     * @param  bool  $strict  See {@see self::convert()}.
     */
    public function getConversionRate($fromCurrency, $toCurrency, $company = null, $date = null, bool $strict = false)
    {
        if ($fromCurrency->id === $toCurrency->id) {
            return 1;
        }

        $company = $company ?? Auth::user()?->defaultCompany;

        $date = $date ?? now()->toDateString();

        // Prefer a real, approved rate from the accounting plugin's Exchange
        // Rates workflow (accounting_exchange_rates) over the legacy
        // CurrencyRate table below. This model lives in the lowest-level
        // plugin and must not import a class from `accounting` (accounting
        // depends on this plugin, never the reverse -- see AGENTS.md), so
        // this queries the table directly by name rather than through the
        // Accounting\Models\ExchangeRate model. Without this, no invoice or
        // bill in any foreign currency ever saw a real exchange rate: this
        // method fell through to the legacy table (which nothing populates
        // via the app's own UI) and silently returned 1.0, even with a real,
        // approved rate sitting in Exchange Rates. Confirmed live: a real
        // USD invoice posted with its PKR-equivalent debit/credit set to the
        // raw USD figures, a ~278x understatement at the rate used here.
        $newRate = $this->resolveApprovedExchangeRate($fromCurrency, $toCurrency, $company, $date);

        if ($newRate !== null) {
            return $newRate;
        }

        $toRateRecord = $toCurrency->rates()
            ->where(function ($query) use ($company) {
                $query->whereNull('company_id');

                if ($company) {
                    $query->orWhere('company_id', $company->id);
                }
            })
            ->whereDate('name', '<=', $date)
            ->orderByDesc('name')
            ->first();

        if ($toRateRecord) {
            return $toRateRecord->rate;
        }

        $fromLabel = $fromCurrency->code ?: $fromCurrency->name;
        $toLabel = $toCurrency->code ?: $toCurrency->name;

        if ($strict) {
            throw new RuntimeException(
                "There's no exchange rate set up for converting {$fromLabel} to {$toLabel} on or before {$date}. ".
                'Add one under Accounting → Exchange Rates, then try this again.'
            );
        }

        Log::warning("Currency::getConversionRate() fell back to a 1:1 rate for {$fromLabel} to {$toLabel} on {$date} -- no CurrencyRate record was found.");

        return 1.0;
    }

    /**
     * Looks up a real, approved rate from accounting_exchange_rates for the
     * from->to pair, or its inverse (a rate is stored once per pair, e.g.
     * "1 USD = 278.50 PKR", and must serve both PKR->USD and USD->PKR
     * lookups). Returns null -- not 1.0 -- when nothing is found, so the
     * caller can fall through to the legacy table/strict-throw/1.0-fallback
     * chain below exactly as before.
     */
    private function resolveApprovedExchangeRate(self $fromCurrency, self $toCurrency, $company, string $date): ?float
    {
        $companyCondition = fn ($query) => $query->where(function ($q) use ($company) {
            $q->whereNull('company_id');

            if ($company) {
                $q->orWhere('company_id', $company->id);
            }
        });

        $direct = DB::table('accounting_exchange_rates')
            ->where('source_currency_id', $fromCurrency->id)
            ->where('target_currency_id', $toCurrency->id)
            ->where('approval_status', 'approved')
            ->whereDate('effective_date', '<=', $date)
            ->tap($companyCondition)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('rate');

        if ($direct !== null) {
            return (float) $direct;
        }

        $inverse = DB::table('accounting_exchange_rates')
            ->where('source_currency_id', $toCurrency->id)
            ->where('target_currency_id', $fromCurrency->id)
            ->where('approval_status', 'approved')
            ->whereDate('effective_date', '<=', $date)
            ->tap($companyCondition)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('rate');

        if ($inverse !== null && (float) $inverse != 0.0) {
            return 1 / (float) $inverse;
        }

        return null;
    }

    public function round(float $amount): float
    {
        return float_round($amount, precisionRounding: $this->rounding);
    }

    public function compareAmounts($amount1, $amount2)
    {
        return float_compare($amount1, $amount2, precisionRounding: $this->rounding);
    }

    public function isZero($amount)
    {
        return $this->floatIsZero($amount, precisionRounding: $this->rounding);
    }

    protected function floatIsZero($value, $precisionDigits = null, $precisionRounding = null)
    {
        $epsilon = $this->floatCheckPrecision($precisionDigits, $precisionRounding);

        return $value == 0.0 || abs($this->floatRound($value, $epsilon)) < $epsilon;
    }

    protected function floatCheckPrecision($precisionDigits = null, $precisionRounding = null)
    {
        if ($precisionRounding !== null && $precisionDigits === null) {
            if ($precisionRounding <= 0) {
                throw new InvalidArgumentException("precision_rounding must be positive, got {$precisionRounding}");
            }

            return $precisionRounding;
        } elseif ($precisionDigits !== null && $precisionRounding === null) {
            if (! is_int($precisionDigits) && ! $this->isInteger($precisionDigits)) {
                throw new InvalidArgumentException("precision_digits must be a non-negative integer, got {$precisionDigits}");
            }

            if ($precisionDigits < 0) {
                throw new InvalidArgumentException("precision_digits must be a non-negative integer, got {$precisionDigits}");
            }

            return pow(10, -$precisionDigits);
        } else {
            throw new InvalidArgumentException('exactly one of precision_digits and precision_rounding must be specified');
        }
    }

    protected function floatRound($value, $precisionRounding)
    {
        if ($precisionRounding == 0) {
            return $value;
        }

        return round($value / $precisionRounding) * $precisionRounding;
    }

    protected function isInteger($value)
    {
        return is_numeric($value) && floatval($value) == intval($value);
    }

    protected static function newFactory()
    {
        return CurrencyFactory::new();
    }
}
