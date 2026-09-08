<?php

namespace Webkul\Employee\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeRequestService
{
    public function __construct(
        protected ApprovalEngine $approvals,
        protected HrHierarchyService $hierarchy,
    ) {}

    public function submit(EmployeeRequest $request, User $requester): ApprovalRequest
    {
        $request->loadMissing(['employee', 'requestType', 'company']);
        $this->assertRequestIntegrity($request, $requester);

        // is_financial and requires_amount are independent toggles on the request
        // type — an admin can create a type that posts to Accounting
        // (is_financial) without also marking the amount required, which would
        // otherwise let a request with no amount reach approval and only fail
        // once createAccountingDraft() runs. Require a positive amount for
        // either flag so a financial request can never be submitted amountless
        // in the first place.
        if (($request->requestType->requires_amount || $request->requestType->is_financial)
            && BigDecimal::of((string) ($request->amount ?? 0))->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('This employee request type requires a positive amount.');
        }
        if ($request->requestType->requires_document && empty($request->attachments)) {
            throw new RuntimeException('This employee request type requires a supporting document.');
        }

        $approval = $this->approvals->submit(
            $request,
            $requester,
            $request->requestType->approval_request_type,
            $request->amount !== null ? (string) $request->amount : null,
            [
                'company_id'      => (int) $request->company_id,
                'employee_id'     => (int) $request->employee_id,
                'department_id'   => $request->employee->department_id,
                'team_id'         => $request->employee->team_id,
                'request_type_id' => (int) $request->request_type_id,
                'request_code'    => $request->requestType->code,
                'category'        => $request->requestType->category,
            ],
        );
        $request->update([
            'approval_request_id' => $approval->id,
            'reference'           => $request->reference ?: 'HR-'.$request->company_id.'-'.$request->id,
            'status'              => 'pending_approval',
            'submitted_at'        => now(),
            'rejection_reason'    => null,
            'rejected_at'         => null,
        ]);

        return $approval;
    }

    public function approve(EmployeeRequest $request, User $actor, ?string $reason = null): EmployeeRequest
    {
        $approval = $request->approvalRequest ?? throw new RuntimeException('The employee request has not been submitted.');
        $this->approvals->approve($approval, $actor, $reason, ['status' => $request->status], ['status' => 'approved']);

        return $this->synchronize($request);
    }

    public function reject(EmployeeRequest $request, User $actor, string $reason): EmployeeRequest
    {
        $approval = $request->approvalRequest ?? throw new RuntimeException('The employee request has not been submitted.');
        $this->approvals->reject($approval, $actor, $reason, ['status' => $request->status], ['status' => 'rejected']);

        return $this->synchronize($request);
    }

    public function synchronize(EmployeeRequest $request): EmployeeRequest
    {
        $request->load(['approvalRequest.decisions', 'requestType', 'company']);
        $approval = $request->approvalRequest;
        if (! $approval) {
            return $request;
        }
        if ($approval->status === 'rejected') {
            $request->update([
                'status'           => 'rejected',
                'rejected_at'      => $approval->completed_at ?? now(),
                'rejection_reason' => $approval->decisions->last()?->reason,
            ]);

            return $request->fresh();
        }
        if ($approval->status !== 'approved') {
            return $request;
        }

        $request->update(['status' => 'approved', 'approved_at' => $approval->completed_at ?? now()]);
        if ($request->requestType->is_financial) {
            $this->createAccountingDraft($request->fresh(['requestType', 'company']));
        }

        return $request->fresh(['approvalRequest', 'accountingMove.lines']);
    }

    public function createAccountingDraft(EmployeeRequest $request): EmployeeRequest
    {
        if ($request->status !== 'approved' || ! $request->requestType->is_financial) {
            throw new RuntimeException('Only approved financial employee requests can be sent to Accounting.');
        }
        if ($request->accounting_move_id) {
            return $request;
        }
        if (BigDecimal::of((string) ($request->amount ?? 0))->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('A positive amount is required for Accounting integration.');
        }
        if ((int) $request->currency_id !== (int) $request->company->currency_id) {
            throw new RuntimeException('Financial employee requests must use the company currency until an approved HR exchange-rate workflow is configured.');
        }

        $type = $request->requestType;
        $journal = Journal::query()->whereKey($type->journal_id)->where('company_id', $request->company_id)->first();
        if (! $journal || $journal->type !== JournalType::GENERAL) {
            throw new RuntimeException('The employee request type requires a company-owned General Journal.');
        }
        $debit = $this->validatedAccount((int) $type->debit_account_id, (int) $request->company_id);
        $credit = $this->validatedAccount((int) $type->credit_account_id, (int) $request->company_id);
        if ($debit->is($credit)) {
            throw new RuntimeException('Employee request debit and credit accounts must be different.');
        }

        DB::transaction(function () use ($request, $journal, $debit, $credit): void {
            $request = EmployeeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->accounting_move_id) {
                return;
            }
            $amount = BigDecimal::of((string) $request->amount)->toScale(4)->__toString();
            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'             => $journal->id,
                'company_id'             => $request->company_id,
                'currency_id'            => $request->currency_id,
                'original_currency_id'   => $request->currency_id,
                'company_currency_id'    => $request->currency_id,
                'date'                   => $request->approved_at?->toDateString() ?? now()->toDateString(),
                'name'                   => 'Employee request '.$request->reference,
                'reference'              => $request->reference,
                'move_type'              => MoveType::ENTRY->value,
                'state'                  => MoveState::DRAFT->value,
                'accounting_source_type' => 'employee_request',
                'accounting_source_id'   => $request->id,
                'review_status'          => 'awaiting_review',
                'conversion_status'      => ConversionStatus::Complete->value,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            $date = $request->approved_at?->toDateString() ?? now()->toDateString();
            DB::table('accounts_account_move_lines')->insert([
                $this->accountingLine($request, $moveId, $journal->id, $debit->id, $date, $amount, '0', 0),
                $this->accountingLine($request, $moveId, $journal->id, $credit->id, $date, '0', $amount, 1),
            ]);
            $request->update([
                'accounting_move_id'      => $moveId,
                'posted_to_accounting_at' => $now,
            ]);
        });

        return $request->fresh(['accountingMove.lines']);
    }

    private function assertRequestIntegrity(EmployeeRequest $request, User $requester): void
    {
        if (! in_array($request->status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only draft or rejected employee requests can be submitted.');
        }
        if ((int) $request->employee?->company_id !== (int) $request->company_id
            || (int) $request->requestType?->company_id !== (int) $request->company_id
            || ! $request->requestType?->is_active) {
            throw new RuntimeException('Employee request, employee, and request type must belong to the same company.');
        }
        if ((int) $request->employee->user_id !== (int) $requester->id) {
            $this->hierarchy->assertCanManage($requester, $request->employee);
        }
    }

    private function validatedAccount(int $accountId, int $companyId): Account
    {
        $account = Account::query()
            ->postable()
            ->whereKey($accountId)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->first();
        if (! $account) {
            throw new RuntimeException('Employee request accounting accounts must be active, postable, and owned by the company.');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function accountingLine(
        EmployeeRequest $request,
        int $moveId,
        int $journalId,
        int $accountId,
        string $date,
        string $debit,
        string $credit,
        int $sort,
    ): array {
        $signed = BigDecimal::of($debit)->minus($credit)->__toString();

        return [
            'move_id'                => $moveId,
            'journal_id'             => $journalId,
            'company_id'             => $request->company_id,
            'company_currency_id'    => $request->currency_id,
            'currency_id'            => $request->currency_id,
            'original_currency_id'   => $request->currency_id,
            'account_id'             => $accountId,
            'date'                   => $date,
            'debit'                  => $debit,
            'credit'                 => $credit,
            'balance'                => $signed,
            'original_debit'         => $debit,
            'original_credit'        => $credit,
            'original_signed_amount' => $signed,
            'company_debit'          => $debit,
            'company_credit'         => $credit,
            'company_signed_amount'  => $signed,
            'amount_currency'        => $signed,
            'conversion_status'      => ConversionStatus::Complete->value,
            'parent_state'           => MoveState::DRAFT->value,
            'name'                   => $request->title,
            'reference'              => $request->reference,
            'sort'                   => $sort,
            'created_at'             => now(),
            'updated_at'             => now(),
        ];
    }
}
