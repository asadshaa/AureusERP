# Aureus ERP — Remaining Manual Tests

Self-contained instructions: what to click, what to enter, and exactly what result to expect vs. what would mean something's wrong. Do these in order, report back what the app actually showed at each step, and I'll verify it against the ledger.

**Who for everything below:** Raza Afzal (Admin) or Khurram (Accounting Manager) — same as every accounting action this whole session.

---

## PART A — Verify the 3 newest fixes through the real UI

These were only verified via rolled-back database tests so far, not the actual browser flow. Do these first.

### A1. Multi-currency invoice (highest-severity fix)

**Accounting → Customers → Invoices → New**
- Customer: `QA-Gulf Logistics FZE`
- Currency: **USD**
- Line: `Freight Transport - Karachi to Lahore (40ft Container)`, quantity 1
- **Do not type anything into Unit Price manually** — let it auto-fill after picking the product.

**Expect:** Unit Price auto-fills to approximately **305.21** (not 85000). Tax ≈ **54.94**, Total ≈ **360.15**.

Save as draft, then **Confirm** (post it). Then tell me the invoice number — I'll check the actual posted journal to confirm the debit/credit lines show ~Rs. 84,780 (the PKR equivalent), not the raw USD figure.

**If Unit Price still shows 85000**, the fix didn't take — report that immediately, don't proceed to post it.

### A2. Bank reconciliation double-posting guard

This re-attempts the exact scenario that used to silently create phantom cash, to confirm it's now blocked.

**Accounting → Accounting → Transaction Mapping.** Find any bank statement line that's currently `unmapped` (if none exist, we'll need to import a small test statement first — tell me and I'll prepare one). Open **Edit** on it and manually set:
- Offset GL: **101403 Outstanding Receipts**
- Cash flow category: **Operating - Receipts**

Try **Approve → Generate draft → Post**, same three steps as before.

**Expect:** It should now **fail with an error** at the Post step (or possibly at Generate draft), saying something like *"This posting would drive a payment-clearing account past its expected balance... Check whether the underlying invoice/bill payment has already been reconciled."* This is the fix working correctly — it should refuse to post, not silently succeed.

**If it posts successfully with no error**, the fix didn't take — report that immediately, and do **not** proceed to reverse anything until I confirm what happened.

### A3. Payment method line direction (low-visible-impact fix, quick check)

**On any unpaid invoice or bill, click Pay** (register a payment) and check the **Payment Method** dropdown. This should show sensible options for the direction (e.g., a customer invoice payment should offer inbound-type methods). This one is unlikely to show any visible difference since our real journals already had both directions configured — just confirm the Pay screen still works normally with no errors. Nothing more to verify here.

---

## PART B — Tax edge cases (original test plan, not yet done)

No new setup needed — reuses `QA-Zeeshan Textiles (Pvt) Ltd` and `QA-Al-Khair Fuel Station`.

### B1. Wrong-type tax on an invoice

Try to apply **18% Input Tax (Purchases)** (a *purchase*-type tax) to a **customer invoice** line, instead of the correct 18% Sales Tax (GST).

**Expect:** The tax picker on an invoice line should either not offer purchase-type taxes at all, or if it lets you pick one, applying it should be flagged as wrong. Tell me exactly what happens — does the dropdown even show it as an option?

### B2. Inactive tax

**Accounting → Configurations → (find the Taxes screen)** — open the old **"15%"** sales tax (id 1, currently active) and **deactivate** it. Then try to create a new customer invoice line and see if the deactivated 15% tax still shows up as selectable.

**Expect:** An inactive tax should **not** appear in the picker for new lines. Tell me if it still shows up. Afterward, **reactivate it** to restore the original state (or tell me and I'll do it via a data fix, matching this session's established pattern).

### B3. Missing/incomplete tax setup

Look at a tax's configuration (**Accounting → Configurations → Taxes → 18% Sales Tax (GST)**) and check its **Invoice Repartition** / **Refund Repartition** lines. Confirm each repartition line has a real GL account attached (we already know these should point to `251000 Tax Received` for sales and `131000 Tax Paid` for purchases — verified earlier this session). This is really just a confirmation step, not expected to fail — tell me if anything looks blank/misconfigured.

### B4. Amount check

Create one more invoice for `QA-Zeeshan Textiles (Pvt) Ltd`, any product/amount, and manually verify the tax line calculates to exactly the expected percentage of the subtotal (e.g., an Rs. 10,000 line with 18% GST should show exactly Rs. 1,800 tax, Rs. 11,800 total). This is a basic sanity check on tax math — should just work, low risk of finding anything, but it's on the original test plan.

---

## PART C — Google Drive invoice ingestion — BLOCKED, do not attempt

Checked directly: **no Google Drive credentials are configured in this environment** (`config('services.google.client_id')` is empty, no `GOOGLE_DRIVE_*`/`DRIVE_*` environment variables set).

This phase needs, before any testing can start:
1. A real Google Cloud project with Drive API enabled and OAuth credentials.
2. Those credentials added to this app's `.env` / config.
3. A real Google Drive folder connected through whatever setup screen this app provides for Drive integration.
4. A real PDF invoice file uploaded to that folder to test ingestion against.

None of this exists right now. **Skip this phase entirely** unless you specifically want to set up real Google Drive credentials first — that's an infrastructure task, not a continuation of app testing, and I'd need you to provide/create the actual Google Cloud credentials since I cannot create third-party accounts or credentials on your behalf.

---

## What to send back

For each test above, just tell me:
1. What you entered/clicked.
2. What the app actually showed (numbers, error messages, whatever appeared).

I'll compare each result against the "Expect" line and confirm pass/fail, then check the real ledger/database directly to independently verify anything that matters financially.
