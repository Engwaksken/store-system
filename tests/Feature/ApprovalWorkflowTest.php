<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\MaterialApproval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** UAT §Tests: request eligibility, PM / Overall separation, rejection and revision. */
class ApprovalWorkflowTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_staff_not_assigned_to_project_cannot_request_for_it(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->wf()->createDraft($this->outsider, $this->project, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 1]]);
    }

    public function test_unassigned_staff_direct_http_post_is_forbidden(): void
    {
        $this->actingAs($this->outsider)->post(route('requests.store'), [
            'project_id' => $this->project->id, 'purpose' => 'x', 'lines' => [['item_id' => $this->cement->id, 'requested_qty' => 1]],
        ])->assertForbidden();
        $this->assertDatabaseCount('material_requests', 0);
    }

    public function test_submit_snapshots_project_manager_and_moves_to_submitted(): void
    {
        $req = $this->submittedRequest();
        $this->assertSame(RequestStatus::Submitted, $req->status);
        $this->assertSame($this->pm->id, $req->project_manager_id);
    }

    public function test_wrong_pm_cannot_approve(): void
    {
        $req = $this->submittedRequest();
        $this->actingAs($this->otherPm)->post(route('requests.pm-decision', $req), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->overall)->post(route('requests.pm-decision', $req), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('requests.pm-decision', $req), ['decision' => 'approved'])->assertForbidden();
        $this->assertSame(RequestStatus::Submitted, $req->fresh()->status);
    }

    public function test_overall_cannot_decide_before_pm(): void
    {
        $req = $this->submittedRequest();
        $this->expectException(WorkflowException::class);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
    }

    public function test_pm_reject_blocks_overall_approval_and_issue(): void
    {
        $this->receive($this->cement, 100);
        $req = $this->submittedRequest();
        $this->actingAs($this->pm)->post(route('requests.pm-decision', $req), ['decision' => 'rejected', 'reason' => 'Too much'])->assertRedirect();
        $this->assertSame(RequestStatus::PmRejected, $req->fresh()->status);

        $this->actingAs($this->overall)->post(route('requests.overall-decision', $req), ['decision' => 'approved'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->store)->post(route('issues.store', $req), ['qty' => [$req->lines->first()->id => 1], 'recipient_name' => 'X'])->assertSessionHasErrors('workflow');
        $this->assertSame('100.000', $this->onHand($this->cement));
    }

    public function test_reject_requires_reason(): void
    {
        $req = $this->submittedRequest();
        $this->actingAs($this->pm)->post(route('requests.pm-decision', $req), ['decision' => 'rejected', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(RequestStatus::Submitted, $req->fresh()->status);
    }

    public function test_overall_reject_blocks_issue(): void
    {
        $this->receive($this->cement, 100);
        $req = $this->submittedRequest();
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'rejected', 'No budget');
        $this->assertSame(RequestStatus::OverallRejected, $req->fresh()->status);

        $this->expectException(WorkflowException::class);
        app(\App\Services\IssueService::class)->issue($this->store, $req, [$req->lines->first()->id => 1], 'X');
    }

    public function test_storekeeper_cannot_issue_until_both_stages_complete(): void
    {
        $this->receive($this->cement, 100);
        $req = $this->submittedRequest();
        $line = $req->lines->first();
        $this->actingAs($this->store)->get(route('issues.create', $req))->assertForbidden();
        $this->actingAs($this->store)->post(route('issues.store', $req), ['qty' => [$line->id => 1], 'recipient_name' => 'X'])->assertSessionHasErrors('workflow');

        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->actingAs($this->store)->post(route('issues.store', $req), ['qty' => [$line->id => 1], 'recipient_name' => 'X'])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('material_issues', 0);
        $this->assertSame('100.000', $this->onHand($this->cement));
    }

    public function test_approvers_cannot_issue_and_storekeeper_cannot_approve(): void
    {
        $this->receive($this->cement, 100);
        $req = $this->submittedRequest();
        $this->actingAs($this->store)->post(route('requests.pm-decision', $req), ['decision' => 'approved'])->assertForbidden();
        $req = $this->approvedRequest();
        $this->actingAs($this->overall)->post(route('issues.store', $req), ['qty' => [$req->lines->first()->id => 1], 'recipient_name' => 'X'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('issues.store', $req), ['qty' => [$req->lines->first()->id => 1], 'recipient_name' => 'X'])->assertForbidden();
    }

    public function test_pm_as_requester_cannot_self_approve_admin_substitutes_and_overall_stays_independent(): void
    {
        $req = $this->submittedRequest(5, null, $this->pm);
        $this->assertFalse($this->wf()->canActAsPm($this->pm, $req), 'PM must not approve own request');
        $this->assertTrue($this->wf()->canActAsPm($this->admin, $req));

        $this->wf()->pmDecide($this->admin, $req, 'approved', null);
        $this->assertTrue(MaterialApproval::where('material_request_id', $req->id)->value('was_substitute'));

        $this->assertFalse($this->wf()->canActAsOverall($this->admin, $req->fresh()), 'Admin is not an overall approver');
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $this->assertSame(RequestStatus::OverallApproved, $req->fresh()->status);
    }

    public function test_admin_cannot_substitute_when_project_pm_is_not_the_requester(): void
    {
        $req = $this->submittedRequest();
        $this->assertFalse($this->wf()->canActAsPm($this->admin, $req));
    }

    public function test_approved_quantities_can_only_be_reduced(): void
    {
        $req = $this->submittedRequest(10);
        $line = $req->lines->first();
        try {
            $this->wf()->pmDecide($this->pm, $req, 'approved', null, [$line->id => 11]);
            $this->fail('Over-approval accepted');
        } catch (WorkflowException) {
        }
        $this->wf()->pmDecide($this->pm, $req, 'approved', null, [$line->id => 8]);
        try {
            $this->wf()->overallDecide($this->overall, $req, 'approved', null, [$line->id => 9]);
            $this->fail('Overall exceeded PM quantity');
        } catch (WorkflowException) {
        }
        $this->wf()->overallDecide($this->overall, $req, 'approved', null, [$line->id => 6]);
        $line->refresh();
        $this->assertSame('10.000', (string) $line->requested_qty);
        $this->assertSame('8.000', (string) $line->pm_approved_qty);
        $this->assertSame('6.000', (string) $line->final_approved_qty);
    }

    public function test_revise_after_rejection_creates_new_version_and_requires_fresh_approvals(): void
    {
        $req = $this->submittedRequest(10);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'rejected', 'Reduce');

        $this->actingAs($this->staff)->post(route('requests.revise', $req))->assertRedirect(route('requests.edit', $req));
        $req->refresh();
        $this->assertSame(RequestStatus::Draft, $req->status);
        $this->assertSame(2, $req->version);
        $this->assertNull($req->lines->first()->pm_approved_qty);
        $this->assertDatabaseCount('material_approvals', 2); // history kept

        $this->wf()->submit($this->staff, $req);
        $this->assertFalse(app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($this->overall)->allows('overall-decide', $req->fresh()));
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $this->assertSame(RequestStatus::OverallApproved, $req->fresh()->status);
        $this->assertDatabaseCount('material_approvals', 4);
    }

    public function test_only_draft_lines_are_editable(): void
    {
        $req = $this->submittedRequest(10);
        $this->actingAs($this->staff)->put(route('requests.update', $req), [
            'project_id' => $this->project->id, 'purpose' => 'changed', 'lines' => [['item_id' => $this->cement->id, 'requested_qty' => 50]],
        ])->assertForbidden();
        $this->assertSame('10.000', (string) $req->lines()->first()->requested_qty);
    }

    public function test_approval_records_are_immutable(): void
    {
        $req = $this->submittedRequest();
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $a = MaterialApproval::first();
        $this->expectException(LogicException::class);
        $a->update(['decision' => 'rejected']);
    }

    public function test_second_decision_on_same_step_is_refused(): void
    {
        $req = $this->submittedRequest();
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->expectException(WorkflowException::class);
        $this->wf()->pmDecide($this->pm, $req, 'rejected', 'changed my mind');
    }

    public function test_client_cannot_set_status_through_hidden_fields(): void
    {
        $this->actingAs($this->staff)->post(route('requests.store'), [
            'project_id' => $this->project->id, 'purpose' => 'x', 'status' => 'overall_approved', 'version' => 9,
            'lines' => [['item_id' => $this->cement->id, 'requested_qty' => 2, 'final_approved_qty' => 2]],
        ])->assertRedirect();
        $req = \App\Models\MaterialRequest::first();
        $this->assertSame(RequestStatus::Draft, $req->status);
        $this->assertSame(1, $req->version);
        $this->assertNull($req->lines->first()->final_approved_qty);
    }
}
