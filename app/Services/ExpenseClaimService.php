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
use Illuminate\Support\Facades\Storage;

/**
 * Expense claims: submitted → approved / rejected → paid.
 *
 *  submit  – any active user assigned to the project (staff, PM) or Admin/Accountant.
 *  approve – the project's PM; if the claimant IS the PM, the Overall Manager or Owner/Admin.
 *            Overall Manager / Admin may also approve any claim. Never the claimant. Rejection needs a reason.
 *  pay     – Accountant / Admin records the payment: this creates exactly one Expense row
 *            (expenses.expense_claim_id is unique), so a claim can never be counted twice in project cost.
 *  edit    – the claimant, while the claim is awaiting approval or after it was rejected (saving a rejected claim
 *            resubmits it; the rejection stays in the activity log). Approvers holding an older copy are refused.
 *  delete  – the claimant or Owner/Admin, only while awaiting approval or after rejection. Approved and paid
 *            claims are financial records and are never deleted. Every deletion is kept in the activity log.
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

    public function canEdit(User $user, ExpenseClaim $claim): bool
    {
        return $user->is_active && in_array($claim->status, ['submitted', 'rejected'], true) && $user->id === $claim->claimant_id;
    }

    public function canDelete(User $user, ExpenseClaim $claim): bool
    {
        return $user->is_active && in_array($claim->status, ['submitted', 'rejected'], true)
            && ($user->id === $claim->claimant_id || $user->isAdmin());
    }

    /** The claimant changes a claim that is still awaiting approval. $data as for submit(); receipt_path only when replaced. */
    public function update(User $user, ExpenseClaim $claim, array $data): ExpenseClaim
    {
        $project = Project::findOrFail($data['project_id']);
        if (! ($user->hasRole(Role::Admin, Role::Accountant, Role::OverallManager) || $user->isAssignedTo($project))) {
            throw new AuthorizationException('You can only claim against projects you are assigned to.');
        }
        $amount = Decimal::of($data['amount'], 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new WorkflowException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $claim, $data, $project, $amount) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (! $this->canEdit($user, $claim)) {
                throw new WorkflowException("Claim {$claim->claim_no} can no longer be edited: it is {$claim->statusLabel()}.");
            }
            $before = $claim->only('project_id', 'category_id', 'cost_code_id', 'incurred_on', 'amount', 'description', 'status', 'approver_id', 'decided_at', 'decision_reason');
            $wasRejected = $claim->status === 'rejected';
            $oldReceipt = $claim->receipt_path;
            $claim->fill([
                'project_id' => $project->id, 'category_id' => $data['category_id'], 'cost_code_id' => $data['cost_code_id'] ?? null,
                'incurred_on' => $data['incurred_on'], 'amount' => $amount, 'description' => $data['description'],
            ] + (isset($data['receipt_path']) ? ['receipt_path' => $data['receipt_path']] : []));
            if ($wasRejected) {
                // Back to the approver as a fresh submission; the old decision is kept in the activity log ($before).
                $claim->forceFill(['status' => 'submitted', 'approver_id' => null, 'decided_at' => null, 'decision_reason' => null]);
            }
            $claim->save();
            if (isset($data['receipt_path']) && $oldReceipt) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldReceipt));
            }
            ActivityLog::record($wasRejected ? 'claim.resubmitted' : 'claim.updated', "Expense claim {$claim->claim_no} "
                .($wasRejected ? 'corrected after rejection and resubmitted' : 'edited by the claimant'), $claim, ['before' => $before]);
            app(WorkflowNotifier::class)->claimSubmitted($claim);   // the approver sees the new figures

            return $claim;
        });
    }

    public function delete(User $user, ExpenseClaim $claim): void
    {
        DB::transaction(function () use ($user, $claim) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (! $this->canDelete($user, $claim)) {
                throw new WorkflowException("Claim {$claim->claim_no} cannot be deleted: it is {$claim->statusLabel()}.");
            }
            $snapshot = $claim->only('claim_no', 'project_id', 'claimant_id', 'incurred_on', 'amount', 'description', 'status');
            $receipt = $claim->receipt_path;
            ActivityLog::record('claim.deleted', "Expense claim {$claim->claim_no} ({$claim->statusLabel()}, ".ugx($claim->amount).' '.currency().") deleted by {$user->name}", null, $snapshot);
            $claim->delete();
            if ($receipt) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($receipt));
            }
        });
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

    /** $seenAt: updated_at of the copy the approver looked at; a claim edited since then is refused. */
    public function decide(User $user, ExpenseClaim $claim, string $decision, ?string $reason, ?string $seenAt = null): ExpenseClaim
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

        return DB::transaction(function () use ($user, $claim, $decision, $reason, $seenAt) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== 'submitted') {
                throw new WorkflowException("Claim {$claim->claim_no} was already decided.");
            }
            if ($seenAt !== null && $claim->updated_at?->toIso8601String() !== $seenAt) {
                throw new WorkflowException("The claimant changed claim {$claim->claim_no} after you opened it. Reload and review it again.");
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
