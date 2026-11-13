<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims: submitted → approved / rejected → paid.
 *
 *  submit  – any active user assigned to the project (staff, PM) or Admin/Accountant.
 *  approve – the project's PM; if the claimant IS the PM, the Overall Manager or Owner/Admin.
 *            Overall Manager / Admin may also approve any claim. Never the claimant. Rejection needs a reason.
 *  pay     – Accountant / Admin records the payment: this creates exactly one Expense row
 *            (expenses.expense_claim_id is unique), so a claim can never be counted twice in project cost.
 */
class ExpenseClaimService
{
    public function submit(User $user, array $data): ExpenseClaim
    {
        $project = Project::findOrFail($data['project_id']);
        if (! $user->is_active || ! ($user->hasRole(Role::Admin, Role::Accountant, Role::OverallManager) || $user->isAssignedTo($project))) {
            throw new AuthorizationException('You can only claim against projects you are assigned to.');
        }
        $amount = Decimal::of($data['amount'], 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new WorkflowException('Amount must be greater than zero.');
        }
        $claim = ExpenseClaim::create([
            'claim_no' => DocumentSequence::next('EC'), 'project_id' => $project->id, 'category_id' => $data['category_id'],
            'cost_code_id' => $data['cost_code_id'] ?? null, 'claimant_id' => $user->id, 'incurred_on' => $data['incurred_on'],
            'amount' => $amount, 'description' => $data['description'], 'receipt_path' => $data['receipt_path'] ?? null,
        ]);
        ActivityLog::record('claim.submitted', "Expense claim {$claim->claim_no} for {$project->code}: {$amount}", $claim);
        app(WorkflowNotifier::class)->claimSubmitted($claim);

        return $claim;
    }

    public function canDecide(User $user, ExpenseClaim $claim): bool
    {
        if (! $user->is_active || $claim->status !== 'submitted' || $user->id === $claim->claimant_id) {
            return false;
        }
        if ($user->hasRole(Role::Admin, Role::OverallManager)) {
            return true;
        }

        return $user->role === Role::ProjectManager && $claim->project->manager_id === $user->id;
    }

    public function decide(User $user, ExpenseClaim $claim, string $decision, ?string $reason): ExpenseClaim
    {
        if ($user->id === $claim->claimant_id) {
            throw new AuthorizationException('You cannot approve your own claim.');
        }
        if (! $this->canDecide($user, $claim)) {
            throw new AuthorizationException('You cannot decide this claim.');
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new WorkflowException('Unknown decision.');
        }
        if ($decision === 'rejected' && trim((string) $reason) === '') {
            throw new WorkflowException('A reason is required when rejecting.');
        }

        return DB::transaction(function () use ($user, $claim, $decision, $reason) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== 'submitted') {
                throw new WorkflowException("Claim {$claim->claim_no} was already decided.");
            }
            $claim->forceFill(['status' => $decision, 'approver_id' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason])->save();
            ActivityLog::record('claim.'.$decision, "Expense claim {$claim->claim_no} {$decision}", $claim, ['reason' => $reason]);
            app(WorkflowNotifier::class)->claimDecided($claim);

            return $claim;
        });
    }

    public function pay(User $user, ExpenseClaim $claim, string $paymentMode, ?string $reference): Expense
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::Accountant)) {
            throw new AuthorizationException('Only the Accountant or Owner/Admin records claim payments.');
        }
        if (! in_array($paymentMode, Expense::PAYMENT_MODES, true)) {
            throw new WorkflowException('Unknown payment mode.');
        }

        return DB::transaction(function () use ($user, $claim, $paymentMode, $reference) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== 'approved') {
                throw new WorkflowException("Claim {$claim->claim_no} is {$claim->statusLabel()} and cannot be paid.");
            }
            $expense = Expense::create([
                'project_id' => $claim->project_id, 'category_id' => $claim->category_id, 'cost_code_id' => $claim->cost_code_id,
                'incurred_on' => $claim->incurred_on, 'amount' => $claim->amount, 'payment_mode' => $paymentMode, 'reference' => $reference,
                'description' => "Claim {$claim->claim_no} ({$claim->claimant->name}): {$claim->description}",
                'receipt_path' => $claim->receipt_path, 'recorded_by' => $user->id, 'expense_claim_id' => $claim->id,
            ]);
            $claim->forceFill(['status' => 'paid'])->save();
            ActivityLog::record('claim.paid', "Expense claim {$claim->claim_no} paid as expense #{$expense->id}", $claim);

            return $expense;
        });
    }
}
