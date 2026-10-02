# Aureus ERP — Manual End-to-End Walkthrough Status

**Date:** 2026-09-28
**Company:** Truck It In (Pvt) Ltd (the only company in this deployment)
**Method:** Live manual testing through the real browser UI (Raza Afzal, Admin), with every accounting effect verified directly against the database after each step — not trusting "Saved"/"Posted" UI messages alone.

This picks up from the earlier automated QA audit cycle (see `APP_TESTING_ERRORS.md` / `APP_TESTING_STATUS.md`, defects DEF-001–012, all fixed). This file covers the separate live walkthrough that followed: a full coherent business scenario, clicked through by hand, with the ledger checked line-by-line at every step.

---

## 1. What we tested (done)

A single connected story: a fictional Karachi trucking scenario against the real company.

| Phase | What | Result |
|---|---|---|
| Master data | Created customer `QA-Zeeshan Textiles (Pvt) Ltd`, vendor `QA-Al-Khair Fuel Station`, products `Freight Transport` and `Diesel Fuel` | Done — hit and fixed 2 real bugs along the way (below) |
| Customer invoice | Rs. 85,000 freight invoice, 18% GST | **PASS** — journal matched exactly (Dr AR 100,300 / Cr Sales 85,000 / Cr Tax 15,300) |
| Customer payment | Registered payment against the invoice | **PASS** — correctly staged in Outstanding Receipts, not Bank directly; no double revenue |
| Vendor bill | Rs. 45,000 diesel purchase, 18% Input Tax | **PASS** after fixing a real bug (bill lines were defaulting to sales price/tax, not purchase cost/tax) |
| Vendor payment | Registered payment against the bill | **PASS** — correctly staged in Outstanding Payments |
| Bank statement import | 8-line Meezan Bank statement covering: the real customer payment, the real vendor payment, a bank fee, an internal transfer, a part-payment, a deliberate duplicate-looking line, a no-reference credit, and an unclear POS charge | **PASS** — see detailed findings below |
| Bank reconciliation | Ran the auto-matching engine, approved/posted the two real matches, manually resolved the rest | **PASS**, but this is where the most serious bug of the session was found and reproduced (below) |
| Manual journal entry | Rs. 60,000 accrued driver payroll (Dr Payroll Expense / Cr Salary Payable) | **PASS**, clean |
| Multi-currency | Created USD customer `QA-Gulf Logistics FZE`, set up and approved a real USD→PKR exchange rate, invoiced in USD | **This is where the highest-severity bug of the session was found** — see below. Test invoice was safely reset to draft (not left posted) once the bug was confirmed. |

**Deliberate stress-tests that correctly held up** (not bugs — confirms real safety features work):
- Re-importing a bank statement for an already-covered period → correctly rejected
- Re-importing the exact same file a second time → correctly rejected
- Two bank lines both matching the same real invoice → only one auto-claimed it, the other correctly stayed unmapped
- Trying to Approve a bank mapping with required fields left blank → correctly blocked by form validation

---

## 2. What's left (not done)

| Phase | Why not done |
|---|---|
| Google Drive invoice ingestion (upload a real PDF, auto-classify, post) | Needs a real Google Drive folder actually connected to this app and a real PDF ready — infrastructure setup, not a continuation of what we were doing. Never started. |
| Tax edge cases (wrong-company tax, inactive tax, wrong tax type, missing tax info) | Only the "happy path" (correct tax on a normal invoice/bill) was exercised. The specific invalid-input cases from the original test plan were not tried. |

---

## 3. Bugs found

### Fixed (6, all committed and pushed to `handover main`)

| # | Bug | Commit |
|---|---|---|
| 1 | Creating any new customer/vendor 404'd — a company-scoping fix from earlier this session left new Partner records with no company assigned | `e4792cd` |
| 2 | Vendor bill lines defaulted price/tax from the product's *sales* fields instead of *purchase* fields | `8cdee3a` |
| 3 | "Bank Fees" FS Tag was misconfigured to post to an asset account instead of an expense account (data fix, no code) | — |
| 4 | "Operating Revenue" FS Tag was misconfigured the same way (data fix, no code) | — |
| 5 | **Bank reconciliation double-posting**: manually setting a bank line's Offset GL to an already-cleared clearing account, then Approve → Draft → Post, went through with zero warnings and put real phantom cash (Rs. 100,300) into the Bank account. Reproduced live, cleaned up, and fixed with a guard that blocks posting if it would drive a clearing account past its expected balance. | `71d481d` |
| 6 | **Reverse creates a duplicate**: reversing a posted bank-mapping journal sometimes produced the correct reversal *and* a second exact duplicate of the original (also posted), needing a second manual cleanup. Fixed with a lock + "already reversed" check. | `71d481d` |
| 7 | **Currency conversion silently 1:1** (highest severity): the app has two separate, disconnected exchange-rate systems. Every foreign-currency invoice/bill — form price *and* the actual posted ledger debit/credit — silently used a 1:1 rate, ignoring any real rate approved through the app's own Exchange Rates screen. A real ~Rs. 100,310 USD invoice would have posted as Rs. 360.18 in the books. Fixed by reconnecting the conversion lookup to the real, approved exchange-rate data. Verified zero pre-existing posted foreign-currency moves exist, so no historical data needed correcting. | `f5b31e4` |
| 8 | `PaymentRegister::getBatchAvailableJournals()` compared a `PaymentType` enum to the raw string `'inbound'` with `==`, which is always `false` for a PHP backed enum — so it always resolved to outbound payment method lines regardless of the real payment direction. Didn't cause visible failures in this session's earlier testing because the real seeded journals happen to have both directions configured — confirmed with a discriminating test using a journal with only one direction configured. | `d6d7316` |

All 7 were verified with real reproduction (not just code review), and re-tested against the full `accounts`/`accounting`/`support` test suites via `git stash` A/B comparison — no regressions introduced anywhere.

### Still open

None — all flagged bugs from this walkthrough are now fixed.

---

## 4. Next steps

All flagged bugs are now fixed (7 total, commits `e4792cd`, `8cdee3a`, `71d481d`, `f5b31e4`, `d6d7316`, plus 2 data-only fixes). Next: **manually re-test the two most consequential fixes** (bank reconciliation double-posting guard, and multi-currency conversion) through the real UI, then optionally continue into tax edge-case testing.

Remaining, not done: Google Drive ingestion (needs real Drive setup — skip unless available), tax edge cases (cheap to do, no new setup needed).
