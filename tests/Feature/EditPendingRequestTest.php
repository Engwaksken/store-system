<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\MaterialApproval;
use App\Notifications\WorkflowAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** The requester may edit a draft or a request still waiting for approval; edits go back to the PM as a new version. */
class EditPendingRequestTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function edit($req, $qty, $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->put(route('requests.update', $req), [
            'project_id' => $this->project->id, 'purpose' => 'Slab, revised', 'action' => 'resubmit',
            'lines' => [['item_id' => $this->cement->id, 'requested_qty' => $qty], ['item_id' => $this->steel->id, 'requested_qty' => 4]],
        ]);
    }

    public function test_requester_edits_a_request_waiting_for_the_pm(): void
    {
        Notification::fake();
        $req = $this->submittedRequest(10);
        $this->actingAs($this->staff)->get(route('requests.edit', $req))->assertOk()->assertSee('sends it back to the Project Manager');

        $this->edit($req, 25)->assertRedirect(route('requests.show', $req))->assertSessionHasNoErrors();

        $req->refresh();
        $this->assertSame(RequestStatus::Submitted, $req->status);
        $this->assertSame(2, $req->version);
        $this->assertSame('Slab, revised', $req->purpose);
        $this->assertSame(['25.000', '4.000'], $req->lines()->orderBy('id')->pluck('requested_qty')->map(fn ($q) => (string) $q)->all());
        Notification::assertSentTo($this->pm, WorkflowAlert::class);
    }

    public function test_edit_after_pm_approval_goes_back_to_the_pm_and_old_approval_stays_in_history(): void
    {
        $req = $this->submittedRequest(10);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);

        $this->edit($req, 30)->assertSessionHasNoErrors();
        $req->refresh();
        $this->assertSame(RequestStatus::Submitted, $req->status);
        $this->assertSame(2, $req->version);
        $this->assertTrue($req->lines->every(fn ($l) => $l->pm_approved_qty === null));
        $this->assertSame(1, MaterialApproval::where('material_request_id', $req->id)->where('version', 1)->count());

        // The overall manager cannot decide on it any more; the PM decides the new version.
        $this->expectException(WorkflowException::class);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
    }

    public function test_a_decision_made_from_a_stale_page_is_refused(): void
    {
        $req = $this->submittedRequest(10);
        $this->edit($req, 12);

        $this->actingAs($this->pm)->post(route('requests.pm-decision', $req), ['decision' => 'approved', 'version' => 1])
            ->assertSessionHasErrors();
        $this->assertSame(RequestStatus::Submitted, $req->fresh()->status);

        $this->actingAs($this->pm)->post(route('requests.pm-decision', $req), ['decision' => 'approved', 'version' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PmApproved, $req->fresh()->status);
    }

    public function test_only_the_requester_may_edit_and_not_after_the_final_decision(): void
    {
        $req = $this->submittedRequest(10);
        $this->edit($req, 99, $this->pm)->assertForbidden();
        $this->edit($req, 99, $this->admin)->assertForbidden();

        $approved = $this->approvedRequest(5);
        $this->actingAs($this->staff)->get(route('requests.edit', $approved))->assertForbidden();
        $this->edit($approved, 99)->assertForbidden();
    }
}
