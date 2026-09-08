# Accounting Static Bug Audit

**Method:** No manual testing. 8 parallel agents each read the actual source of one Accounting
sub-domain and reported candidate defects, calibrated against real bugs already found and fixed
in this exact codebase (the outstanding-account company preference, the non-existent
`internal_group` column, the bank-matching offset bug, the `MoveLine` hook-ordering bug, and the
two shared `ApprovalEngine` bugs from the HR audit). Every candidate was then independently
re-read by two adversarial reviewers instructed to refute it.

**Result:** 14 candidates found → 14 confirmed → 0 refuted. **4 of those turned out to be
duplicates** of bugs already fixed in an earlier accounting session (commit `dc792ba`) that had
never been merged into `master` — the branch this audit ran against was missing that whole
commit. Once cherry-picked in and re-checked against the corrected file state, those 4 were
confirmed already resolved and dropped. **10 distinct, new, confirmed bugs remain**, none fixed
yet.

---

## Already fixed (found by the audit, turned out to be duplicates — noted for transparency)

- `MoveLine`'s saving hook computing the GL account before `display_type` was resolved
- `InvoiceResource` / `BillResource` missing company scoping entirely
- `PaymentResource` missing company scoping entirely
- `JournalEntryResource` scoped only by ownership, never by company

All four are confirmed fixed on this branch as of commit `52b5d44`.

---

## P0 — Silent financial data corruption

### 1. A reversed journal entry doesn't actually cancel the original
`plugins/webkul/accounts/src/AccountManager.php:2011`

`reverseMoves()` flips a reversal line's `balance` and `amount_currency` by directly calling
`MoveLine::update()` — but `MoveLine`'s `saving` hook never recomputes `debit`/`credit` from that
flipped balance. The persisted `debit`/`credit` columns stay identical to the original line, so
`balance == debit - credit` breaks, and any report or journal-items view that reads debit/credit
(not the internal `balance` field) sees the "reversal" as a duplicate of the original entry
rather than a cancellation. A posted entry (debit=100/credit=0) "reversed" still shows debit=100
afterward instead of credit=100.

### 2. An unbalanced journal entry can be posted
`plugins/webkul/accounts/src/AccountManager.php:56` (`confirmMove()` / `isConfirmAllowedForMove()`)

`isConfirmAllowedForMove()` checks partner presence, bank archival, total sign, invoice date,
draft-state, non-empty lines, deprecated accounts, journal presence, and currency presence —
every reasonable precondition **except** whether the move's lines actually balance
(`sum(debit) == sum(credit)`). `confirmMove()` then unconditionally flips the state to `POSTED`.
A move with debit=100/credit=50 lines posts cleanly, silently putting the ledger out of balance
by 50.

### 3. Zero-balance check uses the wrong currency's rounding threshold
`plugins/webkul/accounts/src/Models/Payment.php:323` (`computeState()`)

`amount_residual` is always computed and rounded in the **company** currency (confirmed in
`MoveLine.php`). But `computeState()` tests it for zero using `$this->move->currency->isZero(...)`
— the payment's **transaction** currency, not the company currency — and `Currency::isZero()`
uses that specific currency's own `rounding` precision as the threshold. A company on BHD
(rounding 0.001) with a JPY-denominated payment (rounding 1) can have a genuinely-unpaid 0.5 BHD
residual wrongly read as "zero" under JPY's coarser threshold, flipping the payment to `PAID`
while money is still owed. The sibling method `computeReconciliationStatus()` a few lines below
gets this right by matching the residual field to the currency; `computeState()` doesn't.

### 4. A within-file duplicate transaction crashes the entire bank statement import
`plugins/webkul/accounting/src/Services/Bank/BankStatementImportService.php:162`

The validator correctly flags a duplicate transaction as a soft, reviewable error (same tier as
a missing date or zero amount) and the whole design intends the import to still complete, tagged
`ReconciliationFailed`, so the user can review it. But the import loop inserts every transaction
unconditionally with no check against those errors, and the schema has a **hard unique
constraint** on the transaction fingerprint. The second duplicate row's insert throws an uncaught
`QueryException`, which rolls back the entire database transaction — discarding the whole
statement, including every valid, non-duplicate row — and surfaces a raw SQL error instead of the
intended "imported for review" outcome.

---

## P1 — Wrong money moved / matched

### 5. An approved exchange rate can be finalized from a value nobody actually approved
`plugins/webkul/accounting/src/Services/Currency/ExchangeRateApprovalService.php:48`

The record stays editable through the entire approval process (its `Edit` action is only hidden
once `approval_status` is literally `Approved`, and nothing moves it out of `Draft` while a
request is pending — there's no `Pending` value in the status enum at all). `approve()` only
checks that *some* approved `ApprovalRequest` row exists for the subject — no ordering, no
comparison against what was actually captured at submission time. So: submit a rate → an approver
approves the *original* value → before anyone clicks the resource's own "approve" button, the
submitter edits the rate to something else entirely → clicking approve finalizes the **edited**
value using the stale approval, and it immediately feeds live bank-statement currency conversion.

### 6. The same open invoice/bill can be auto-suggested as the match for two different bank lines
`plugins/webkul/accounting/src/Services/Bank/BankMatchingPriorityService.php:40`

Each bank statement line's candidate match is found via a fresh, independent query with no
tracking of which open documents earlier lines in the *same batch* have already claimed (contrast
with the sibling `BankTransferMatchingService`, which does track consumed candidates). Two
statement lines referencing the same invoice both independently see it as their unique match and
both get marked `Suggested` with `confidence = 1` — silently double-claiming a single obligation.

### 7. Matching ignores whether money is coming in or going out
`plugins/webkul/accounting/src/Services/Bank/BankMatchingPriorityService.php:40`

The candidate-move query never checks that a bank **credit** (money in) is being matched to a
receivable or that a bank **debit** (money out) is matched to a payable — only amount and
reference text. `BankMappingService::matches()` already does this direction check for rule-based
matching a few files over; the priority-suggestion path doesn't. An incoming customer payment can
get suggested against an outstanding vendor bill.

### 8. A missing exchange rate silently becomes a 1:1 conversion
`plugins/webkul/support/src/Models/Currency.php:100`

`getConversionRate()` falls back to `1.0` with no error, warning, or flag whenever no rate record
exists for the currency/date/company — unlike the newer `ExchangeRateService::resolve()`, which
throws for exactly this case. A EUR 1000 invoice with no configured rate gets booked as if 1 EUR
= 1 USD, silently corrupting the foreign-currency figures and any FX-based reporting drawn from
them.

---

## P2 — Data integrity

### 9. Chart-of-Accounts import never marks receivable/payable accounts as reconcilable
`plugins/webkul/accounting/src/Services/Coa/CoaImportService.php:196`

Accounts created (or updated) during a CoA import never set the `reconcile` column — it's
nullable with no default, so it stays `false`. `MoveLine::computeAmountResidual()` only tracks a
balance when `reconcile` is true (or the account type is cash/credit-card). A receivable account
imported from a spreadsheet therefore has every invoice against it immediately read as
`amount_residual = 0` — a genuinely unpaid invoice looks fully settled from the moment it's
posted.

### 10. Two accounts with the same code can be created for the same company
`plugins/webkul/accounting/src/Services/Account/CanonicalAccountCreationService.php:186`

GL-code uniqueness is enforced only by a check-then-insert in application code
(`companyAccountCodeExists()` runs before the transaction opens; the actual insert happens
inside it) — there is no unique database constraint on `accounts_accounts.code`, company-scoped
or otherwise, anywhere in the migration history, even though this exact pattern
(`table_company_code_unique`) is used for several sibling tables. Two near-simultaneous requests
with the same code both pass the check before either commits.

---

## Suggested fix order

1. **P0 items 1–4** — silent ledger corruption and a crash that discards valid data, the most
   damaging class.
2. **P1 items 5–8** — wrong money matched/converted, real financial-accuracy risk.
3. **P2 items 9–10** — data-integrity gaps, lower immediate blast radius but compounding over
   time.

Every fix should get the same treatment as the HR audit fixes: explain, fix, add a regression
test, verify fail-then-pass by isolating the fix.
