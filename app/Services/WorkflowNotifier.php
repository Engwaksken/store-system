<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Notifications\WorkflowAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * E-mails the next person in the approval chain (backlog B-25).
 * Sent only after the database transaction commits, and a mail failure never undoes or blocks a decision.
 */
class WorkflowNotifier
{
    public function submitted(MaterialRequest $req): void
    {
        $to = $req->project_manager_id && $req->project_manager_id !== $req->requester_id
            ? User::whereKey($req->project_manager_id)->get()
            : $this->role(Role::Admin);
        $this->send($to, "Approval needed: {$req->request_no}", "{$req->requester->name} submitted material request {$req->request_no} for project {$req->project->code}. It is waiting for the Project Manager decision.", $req);
    }

    public function pmApproved(MaterialRequest $req): void
    {
        $this->send($this->role(Role::OverallManager), "Final approval needed: {$req->request_no}", "Material request {$req->request_no} for project {$req->project->code} was approved by the Project Manager and needs the Overall Manager decision.", $req);
    }

    public function overallApproved(MaterialRequest $req): void
    {
        $this->send($this->role(Role::Storekeeper), "Ready to issue: {$req->request_no}", "Material request {$req->request_no} for project {$req->project->code} is fully approved and ready to issue from the store.", $req);
        $this->send(User::whereKey($req->requester_id)->get(), "Approved: {$req->request_no}", "Your material request {$req->request_no} is fully approved. The storekeeper will issue the materials.", $req);
    }

    public function rejected(MaterialRequest $req, string $step, string $reason): void
    {
        $who = $step === 'pm' ? 'the Project Manager' : 'the Overall Manager';
        $this->send(User::whereKey($req->requester_id)->get(), "Rejected: {$req->request_no}", "Your material request {$req->request_no} was rejected by {$who}. Reason: {$reason}. You can revise and resubmit it.", $req);
    }

    public function issued(MaterialIssue $issue): void
    {
        $req = $issue->request;
        $this->send(User::whereKey($req->requester_id)->get(), "Materials issued: {$issue->issue_no}", "Materials for your request {$req->request_no} were issued on voucher {$issue->issue_no} to {$issue->recipient_name}. Please confirm receipt.",
            null, route('issues.show', $issue), 'Open voucher');
    }

    private function role(Role $role): Collection
    {
        return User::where('role', $role)->where('is_active', true)->get();
    }

    private function send(Collection $users, string $subject, string $body, ?MaterialRequest $req, ?string $url = null, string $action = 'Open request'): void
    {
        $users = $users->where('is_active', true)->values();
        if ($users->isEmpty()) {
            return;
        }
        $url ??= route('requests.show', $req);
        DB::afterCommit(function () use ($users, $subject, $body, $url, $action) {
            try {
                Notification::send($users, new WorkflowAlert($subject, $body, $url, $action));
            } catch (Throwable $e) {
                report($e); // the decision is already saved; never fail the user's action because mail is down
            }
        });
    }
}
