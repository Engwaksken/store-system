<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryItem;
use App\Models\MaterialApproval;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Material request lifecycle: draft, submit, PM decision, Overall decision, revise, cancel.
 * See docs/APPROVAL_STATE_MACHINE.md. Every transition re-reads the request under lockForUpdate.
 */
class RequestWorkflow
{
    public function __construct(private WorkflowNotifier $notifier)
    {
    }

    // ---------------------------------------------------------------- authorization predicates (also used by policies)

    public function canRequestFor(User $user, Project $project): bool
    {
        if (! in_array($project->status, ['planned', 'active'], true)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasRole(Role::Staff, Role::ProjectManager) && $user->isAssignedTo($project);
    }

    /** D2: the project's PM decides; if the PM is the requester, Admin substitutes. Never the requester. */
    public function canActAsPm(User $user, MaterialRequest $req): bool
    {
        if ($user->id === $req->requester_id || ! $user->is_active) {
            return false;
        }
        if ($req->project_manager_id === $user->id && $user->role === Role::ProjectManager) {
            return true;
        }

        return $user->isAdmin() && $this->needsSubstitutePm($req);
    }

    public function needsSubstitutePm(MaterialRequest $req): bool
    {
        return $req->project_manager_id === null || $req->project_manager_id === $req->requester_id;
    }

    /** D1 + D3: overall manager, independent of requester and of the PM-step approver for this version. */
    public function canActAsOverall(User $user, MaterialRequest $req): bool
    {
        if ($user->role !== Role::OverallManager || ! $user->is_active || $user->id === $req->requester_id) {
            return false;
        }
        $pm = MaterialApproval::where('material_request_id', $req->id)->where('version', $req->version)
            ->where('step', MaterialApproval::STEP_PM)->first();

        return ! $pm || $pm->approver_id !== $user->id;
    }

    // ---------------------------------------------------------------- draft handling

    /** @param array<int, array{item_id:int, requested_qty:mixed, notes?:?string}> $lines */
    public function createDraft(User $user, Project $project, string $purpose, ?string $neededOn, array $lines): MaterialRequest
    {
        if (! $this->canRequestFor($user, $project)) {
            throw new AuthorizationException('You are not assigned to this project.');
        }
        $lines = $this->normaliseLines($lines, $project->id);

        return DB::transaction(function () use ($user, $project, $purpose, $neededOn, $lines) {
            $req = MaterialRequest::create([
                'request_no' => DocumentSequence::next('MR'), 'project_id' => $project->id,
                'requester_id' => $user->id, 'purpose' => $purpose, 'needed_on' => $neededOn,
            ]);
            foreach ($lines as $l) {
                $req->lines()->create($l);
            }
            ActivityLog::record('request.created', "Request {$req->request_no} drafted", $req);

            return $req;
        });
    }

    public function updateDraft(User $user, MaterialRequest $req, string $purpose, ?string $neededOn, array $lines): MaterialRequest
    {
        $lines = $this->normaliseLines($lines, $req->project_id);

        return DB::transaction(function () use ($user, $req, $purpose, $neededOn, $lines) {
            $req = $this->lock($req);
            $this->assertRequester($user, $req);
            $this->assertStatus($req, RequestStatus::Draft);
            if (! $this->canRequestFor($user, $req->project)) {
                throw new AuthorizationException('You are no longer assigned to this project.');
            }
            $req->update(['purpose' => $purpose, 'needed_on' => $neededOn]);
            $req->lines()->delete();
            foreach ($lines as $l) {
                $req->lines()->create($l);
            }
            ActivityLog::record('request.updated', "Request {$req->request_no} draft edited", $req);

            return $req;
        });
    }

    public function submit(User $user, MaterialRequest $req): MaterialRequest
    {
        return DB::transaction(function () use ($user, $req) {
            $req = $this->lock($req);
            $this->assertRequester($user, $req);
            $this->assertStatus($req, RequestStatus::Draft);
            $project = Project::findOrFail($req->project_id);
            if (! $this->canRequestFor($user, $project)) {
                throw new AuthorizationException('You are no longer assigned to this project.');
            }
            if ($req->lines()->count() === 0) {
                throw new WorkflowException('Add at least one item before submitting.');
            }
            if ($project->manager_id === null) {
                throw new WorkflowException('This project has no assigned Project Manager. Ask the admin to assign one.');
            }

            $req->forceFill([
                'status' => RequestStatus::Submitted,
                'project_manager_id' => $project->manager_id, // snapshot at submission
                'submitted_at' => now(),
            ])->save();
            ActivityLog::record('request.submitted', "Request {$req->request_no} v{$req->version} submitted", $req);
            $this->notifier->submitted($req);

            return $req;
        });
    }

    public function cancel(User $user, MaterialRequest $req): MaterialRequest
    {
        return DB::transaction(function () use ($user, $req) {
            $req = $this->lock($req);
            if ($user->id !== $req->requester_id && ! $user->isAdmin()) {
                throw new AuthorizationException('Only the requester or admin can cancel.');
            }
            $this->assertStatus($req, RequestStatus::Draft, RequestStatus::Submitted);
            $req->forceFill(['status' => RequestStatus::Cancelled])->save();
            ActivityLog::record('request.cancelled', "Request {$req->request_no} cancelled", $req);

            return $req;
        });
    }

    /** D4: a rejected request returns to DRAFT as a new version; prior decisions stay in history. */
    public function revise(User $user, MaterialRequest $req): MaterialRequest
    {
        return DB::transaction(function () use ($user, $req) {
            $req = $this->lock($req);
            $this->assertRequester($user, $req);
            $this->assertStatus($req, RequestStatus::PmRejected, RequestStatus::OverallRejected);
            $req->lines()->update(['pm_approved_qty' => null, 'final_approved_qty' => null]);
            $req->forceFill([
                'status' => RequestStatus::Draft, 'version' => $req->version + 1,
                'project_manager_id' => null, 'submitted_at' => null,
            ])->save();
            ActivityLog::record('request.revised', "Request {$req->request_no} reopened as v{$req->version}", $req);

            return $req;
        });
    }

    // ---------------------------------------------------------------- decisions

    /** @param array<int|string, mixed> $qtys line_id => approved qty (approve only) */
    public function pmDecide(User $user, MaterialRequest $req, string $decision, ?string $reason, array $qtys = []): MaterialRequest
    {
        return DB::transaction(function () use ($user, $req, $decision, $reason, $qtys) {
            $req = $this->lock($req);
            $this->assertStatus($req, RequestStatus::Submitted);
            if (! $this->canActAsPm($user, $req)) {
                throw new AuthorizationException('You are not the assigned Project Manager for this request.');
            }

            return $this->decide($user, $req, MaterialApproval::STEP_PM, $decision, $reason, $qtys,
                ceiling: 'requested_qty', target: 'pm_approved_qty',
                approved: RequestStatus::PmApproved, rejected: RequestStatus::PmRejected,
                substitute: $user->isAdmin());
        });
    }

    public function overallDecide(User $user, MaterialRequest $req, string $decision, ?string $reason, array $qtys = []): MaterialRequest
    {
        return DB::transaction(function () use ($user, $req, $decision, $reason, $qtys) {
            $req = $this->lock($req);
            $this->assertStatus($req, RequestStatus::PmApproved);
            if (! $this->canActAsOverall($user, $req)) {
                throw new AuthorizationException('You cannot give the overall decision on this request (role or independence rule).');
            }

            return $this->decide($user, $req, MaterialApproval::STEP_OVERALL, $decision, $reason, $qtys,
                ceiling: 'pm_approved_qty', target: 'final_approved_qty',
                approved: RequestStatus::OverallApproved, rejected: RequestStatus::OverallRejected,
                substitute: false);
        });
    }

    private function decide(User $user, MaterialRequest $req, string $step, string $decision, ?string $reason, array $qtys,
        string $ceiling, string $target, RequestStatus $approved, RequestStatus $rejected, bool $substitute): MaterialRequest
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new WorkflowException('Invalid decision.');
        }
        $reason = trim((string) $reason);
        if ($decision === 'rejected' && $reason === '') {
            throw new WorkflowException('A reason is required when rejecting.');
        }

        $lines = $req->lines()->lockForUpdate()->get();
        $snapshot = [];

        if ($decision === 'approved') {
            $anyPositive = false;
            foreach ($lines as $line) {
                $max = (string) $line->{$ceiling};
                $qty = array_key_exists($line->id, $qtys) && $qtys[$line->id] !== '' && $qtys[$line->id] !== null
                    ? Decimal::of($qtys[$line->id]) : Decimal::of($max);
                if (Decimal::cmp($qty, '0') < 0 || Decimal::cmp($qty, $max) > 0) {
                    throw new WorkflowException("Approved quantity for {$line->item->name} must be between 0 and {$max}.");
                }
                $anyPositive = $anyPositive || Decimal::cmp($qty, '0') > 0;
                $line->forceFill([$target => $qty])->save();
                $snapshot[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty' => $qty];
            }
            if (! $anyPositive) {
                throw new WorkflowException('Approve at least one item with a quantity above zero, or reject the request.');
            }
        }

        MaterialApproval::create([
            'material_request_id' => $req->id, 'version' => $req->version, 'step' => $step,
            'approver_id' => $user->id, 'decision' => $decision, 'reason' => $reason ?: null,
            'was_substitute' => $substitute, 'lines_snapshot' => $snapshot ?: null, 'decided_at' => now(),
        ]);
        $req->forceFill(['status' => $decision === 'approved' ? $approved : $rejected])->save();
        ActivityLog::record("request.{$step}_{$decision}", "Request {$req->request_no} v{$req->version} {$decision} at {$step} step", $req, ['reason' => $reason]);
        match (true) {
            $decision === 'rejected' => $this->notifier->rejected($req, $step, $reason),
            $step === MaterialApproval::STEP_PM => $this->notifier->pmApproved($req),
            default => $this->notifier->overallApproved($req),
        };

        return $req;
    }

    // ---------------------------------------------------------------- helpers

    private function lock(MaterialRequest $req): MaterialRequest
    {
        return MaterialRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
    }

    private function assertRequester(User $user, MaterialRequest $req): void
    {
        if ($user->id !== $req->requester_id) {
            throw new AuthorizationException('Only the requester can do this.');
        }
    }

    private function assertStatus(MaterialRequest $req, RequestStatus ...$allowed): void
    {
        if (! in_array($req->status, $allowed, true)) {
            throw new WorkflowException("Request {$req->request_no} is {$req->status->label()}; this action is not allowed now.");
        }
    }

    private function normaliseLines(array $lines, ?int $projectId = null): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (empty($l['item_id'])) {
                continue;
            }
            $id = (int) $l['item_id'];
            if (isset($out[$id])) {
                throw new WorkflowException('Each item may appear only once in a request.');
            }
            $qty = Decimal::of($l['requested_qty'] ?? 0);
            if (Decimal::cmp($qty, '0') <= 0) {
                throw new WorkflowException('Requested quantities must be greater than zero.');
            }
            $boq = empty($l['boq_item_id']) ? null : (int) $l['boq_item_id'];
            if ($boq !== null && ! \App\Models\BoqItem::whereKey($boq)->where('project_id', $projectId)->exists()) {
                throw new WorkflowException('The BOQ line must belong to the request\'s project.');
            }
            $out[$id] = ['item_id' => $id, 'boq_item_id' => $boq, 'requested_qty' => $qty, 'notes' => $l['notes'] ?? null];
        }
        if (! $out) {
            throw new WorkflowException('Add at least one item.');
        }
        $active = InventoryItem::whereIn('id', array_keys($out))->where('is_active', true)->count();
        if ($active !== count($out)) {
            throw new WorkflowException('One or more items are unknown or inactive.');
        }

        return array_values($out);
    }
}
