<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\BoqItem;
use App\Models\CostCode;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseClaimService;
use App\Services\IssueService;
use App\Services\ProjectCostService;
use App\Services\PurchasingService;
use App\Services\StockReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Phase 4: cost codes, budgets, BOQ, milestones, expense claims, commitments and variance. */
class ProjectControlsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private CostCode $concrete, $transport;
    private ExpenseCategory $cat;
    private BoqItem $slab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->concrete = CostCode::create(['code' => '03', 'name' => 'Concrete', 'category' => 'materials']);
        $this->transport = CostCode::create(['code' => '09', 'name' => 'Transport', 'category' => 'equipment']);
        $this->cat = ExpenseCategory::create(['name' => 'Transport']);
        $this->slab = BoqItem::create(['project_id' => $this->project->id, 'cost_code_id' => $this->concrete->id, 'ref' => '3.1',
            'description' => 'Slab', 'unit' => 'm3', 'qty' => 10, 'rate' => 50000, 'amount' => 500000]);
    }

    private function claims(): ExpenseClaimService
    {
        return app(ExpenseClaimService::class);
    }

    private function claim(User $by, $amount = 50000): ExpenseClaim
    {
        return $this->claims()->submit($by, ['project_id' => $this->project->id, 'category_id' => $this->cat->id, 'cost_code_id' => $this->transport->id,
            'incurred_on' => now()->toDateString(), 'amount' => $amount, 'description' => 'Boda']);
    }

    public function test_claim_is_approved_independently_and_paid_into_expenses_exactly_once(): void
    {
        $c = $this->claim($this->staff);
        $this->actingAs($this->staff)->post(route('claims.decide', $c), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->otherPm)->post(route('claims.decide', $c), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->pm)->post(route('claims.decide', $c), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $c->fresh()->status);

        $this->actingAs($this->accountant)->post(route('claims.pay', $c), ['payment_mode' => 'cash'])->assertRedirect();
        $this->actingAs($this->accountant)->post(route('claims.pay', $c), ['payment_mode' => 'cash'])->assertForbidden();
        try {
            $this->claims()->pay($this->accountant, $c->fresh(), 'cash', null);
            $this->fail('Paid twice');
        } catch (WorkflowException) {
        }
        $this->assertSame(1, Expense::where('expense_claim_id', $c->id)->count());
        $this->assertSame('paid', $c->fresh()->status);

        $cost = app(ProjectCostService::class)->summary(collect([$this->project]))->first();
        $this->assertSame('50000.00', $cost->expenses, 'Paid claim counted once as expense');
        $this->assertSame('0.00', $cost->committed, 'and no longer as a commitment');

        // Database guarantee as well: a second expense for the same claim is impossible.
        $this->expectException(UniqueConstraintViolationException::class);
        Expense::create(['project_id' => $this->project->id, 'category_id' => $this->cat->id, 'incurred_on' => now(), 'amount' => 1,
            'payment_mode' => 'cash', 'description' => 'dup', 'recorded_by' => $this->accountant->id, 'expense_claim_id' => $c->id]);
    }

    public function test_pm_claim_goes_to_overall_and_rejection_needs_reason(): void
    {
        $c = $this->claim($this->pm);
        $this->assertFalse($this->claims()->canDecide($this->pm, $c), 'PM cannot approve own claim');
        $this->actingAs($this->overall)->post(route('claims.decide', $c), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($this->overall)->post(route('claims.decide', $c), ['decision' => 'rejected', 'reason' => 'No receipt'])->assertRedirect();
        $this->assertSame('rejected', $c->fresh()->status);
        $this->expectException(WorkflowException::class);
        $this->claims()->pay($this->accountant, $c->fresh(), 'cash', null);
    }

    public function test_staff_cannot_claim_on_unassigned_project(): void
    {
        $other = Project::where('code', 'P-2')->sole();
        $this->expectException(AuthorizationException::class);
        $this->claims()->submit($this->outsider, ['project_id' => $other->id, 'category_id' => $this->cat->id, 'incurred_on' => now()->toDateString(), 'amount' => 1, 'description' => 'x']);
    }

    public function test_boq_line_must_belong_to_the_request_project(): void
    {
        $otherBoq = BoqItem::create(['project_id' => Project::where('code', 'P-2')->value('id'), 'cost_code_id' => $this->concrete->id, 'ref' => '1',
            'description' => 'x', 'unit' => 'm3', 'qty' => 1, 'rate' => 1, 'amount' => 1]);
        $this->expectException(WorkflowException::class);
        $this->wf()->createDraft($this->staff, $this->project, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 1, 'boq_item_id' => $otherBoq->id]]);
    }

    public function test_budget_vs_committed_vs_actual_by_cost_code(): void
    {
        ProjectBudget::create(['project_id' => $this->project->id, 'cost_code_id' => $this->concrete->id, 'amount' => 400000]);
        ProjectBudget::create(['project_id' => $this->project->id, 'cost_code_id' => $this->transport->id, 'amount' => 100000]);

        // Materials against the slab BOQ line: issue 10 bags @ 30,000, return 2.
        $this->receive($this->cement, 20, 30000);
        $req = $this->wf()->createDraft($this->staff, $this->project, 'Slab', null, [['item_id' => $this->cement->id, 'requested_qty' => 10, 'boq_item_id' => $this->slab->id]]);
        $this->wf()->submit($this->staff, $req);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $issue = app(IssueService::class)->issue($this->store, $req->fresh(), [$req->lines()->sole()->id => 10], 'Brian');
        app(StockReturnService::class)->record($this->store, $issue, [$issue->lines()->sole()->id => 2], 'Brian', 'Leftover');

        // Commitment: approved project PO under concrete, 5 of 10 received.
        $buyer = User::factory()->role(Role::Procurement)->create();
        $po = app(PurchasingService::class)->createOrder($buyer, ['supplier_id' => Supplier::create(['code' => 'S', 'name' => 'S'])->id, 'store_id' => 1,
            'project_id' => $this->project->id, 'cost_code_id' => $this->concrete->id], [['item_id' => $this->steel->id, 'qty' => 10, 'unit_price' => 20000]]);
        $po = app(PurchasingService::class)->decideOrder($this->overall, $po, 'approved', null);
        app(\App\Services\GoodsReceiptService::class)->receive($this->store, $po, [$po->lines()->sole()->id => 5]);

        // Transport: one paid claim (actual) and one approved (committed).
        $paid = $this->claim($this->staff, 30000);
        $this->claims()->decide($this->pm, $paid, 'approved', null);
        $this->claims()->pay($this->accountant, $paid, 'cash', null);
        $open = $this->claim($this->staff, 80000);
        $this->claims()->decide($this->pm, $open, 'approved', null);

        $rows = app(ProjectCostService::class)->byCostCode($this->project)->keyBy('cost_code_id');
        $c = $rows[$this->concrete->id];
        $this->assertSame('240000.00', $c->materials, '10×30,000 issued − 2×30,000 returned');
        $this->assertSame('100000.00', $c->committed, '5 open × 20,000 on the PO');
        $this->assertSame('60000.00', $c->variance, '400,000 − (240,000 + 100,000)');
        $this->assertSame('ok', $c->flag);
        $t = $rows[$this->transport->id];
        $this->assertSame('30000.00', $t->actual);
        $this->assertSame('80000.00', $t->committed);
        $this->assertSame('over', $t->flag, '110,000 exposure on a 100,000 budget');

        $boq = app(ProjectCostService::class)->boqConsumption($this->project)->sole();
        $this->assertSame('240000.00', $boq->consumed_value);
        $this->assertSame('8.000', $boq->consumed_qty);

        $sum = app(ProjectCostService::class)->summary(collect([$this->project]))->first();
        $this->assertSame('180000.00', $sum->committed, 'PO 100,000 + approved claim 80,000');
        $this->assertSame('270000.00', $sum->total, 'materials 240,000 + expenses 30,000');
    }

    public function test_controls_page_and_edit_rights(): void
    {
        $this->actingAs($this->pm)->get(route('projects.controls', $this->project))->assertOk()->assertSee('Save budgets');
        $this->actingAs($this->accountant)->get(route('projects.controls', $this->project))->assertOk()->assertDontSee('Save budgets');
        $this->actingAs($this->otherPm)->get(route('projects.controls', $this->project))->assertForbidden();
        $this->actingAs($this->staff)->get(route('projects.controls', $this->project))->assertForbidden();

        $this->actingAs($this->pm)->put(route('projects.budgets.save', $this->project), ['budget' => [$this->concrete->id => 250000]])->assertRedirect();
        $this->assertSame('250000.00', (string) ProjectBudget::where('cost_code_id', $this->concrete->id)->value('amount'));
        $this->actingAs($this->accountant)->put(route('projects.budgets.save', $this->project), ['budget' => [$this->concrete->id => 1]])->assertForbidden();

        $this->actingAs($this->pm)->post(route('projects.boq.store', $this->project), ['ref' => '3.2', 'description' => 'Beams', 'cost_code_id' => $this->concrete->id,
            'unit' => 'm3', 'qty' => 2.5, 'rate' => 100000])->assertRedirect();
        $this->assertSame('250000.00', (string) BoqItem::where('ref', '3.2')->value('amount'));
        $this->actingAs($this->pm)->post(route('projects.boq.store', $this->project), ['ref' => '3.2', 'description' => 'dup', 'cost_code_id' => $this->concrete->id,
            'unit' => 'm3', 'qty' => 1, 'rate' => 1])->assertSessionHasErrors('ref');

        // A BOQ line used by a request cannot be removed.
        $this->wf()->createDraft($this->staff, $this->project, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 1, 'boq_item_id' => $this->slab->id]]);
        $this->actingAs($this->pm)->delete(route('projects.boq.destroy', [$this->project, $this->slab]))->assertSessionHasErrors('boq');
        $this->assertNotNull($this->slab->fresh());

        $this->actingAs($this->pm)->post(route('projects.milestones.store', $this->project), ['name' => 'Slab cast', 'planned_on' => now()->subDay()->toDateString()])->assertRedirect();
        $m = $this->project->milestones()->sole();
        $this->assertSame('late', $m->state());
        $this->actingAs($this->pm)->put(route('projects.milestones.complete', [$this->project, $m]), ['completed_on' => now()->toDateString()])->assertRedirect();
        $this->assertSame('done', $m->fresh()->state());
    }

    public function test_cost_codes_are_managed_by_admin_and_accountant_only(): void
    {
        $this->actingAs($this->pm)->get(route('cost-codes.index'))->assertOk();
        $this->actingAs($this->pm)->post(route('cost-codes.store'), ['code' => 'X', 'name' => 'X', 'category' => 'labour'])->assertForbidden();
        $this->actingAs($this->staff)->get(route('cost-codes.index'))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('cost-codes.store'), ['code' => '10', 'name' => 'Fees', 'category' => 'overheads'])->assertRedirect();
        $this->assertTrue(CostCode::where('code', '10')->exists());
    }

    public function test_finance_reports_render_and_are_scoped(): void
    {
        ProjectBudget::create(['project_id' => $this->project->id, 'cost_code_id' => $this->concrete->id, 'amount' => 400000]);
        $this->claims()->decide($this->pm, $this->claim($this->staff), 'approved', null);
        foreach (['reports.cost-codes', 'reports.boq', 'reports.commitments', 'reports.project-cost'] as $r) {
            $this->actingAs($this->admin)->get(route($r))->assertOk();
            $this->actingAs($this->admin)->get(route($r, ['format' => 'csv']))->assertOk();
        }
        $this->actingAs($this->otherPm)->get(route('reports.commitments'))->assertOk()->assertDontSee('P-1');
        $this->actingAs($this->pm)->get(route('reports.commitments'))->assertOk()->assertSee('P-1');
        $this->actingAs($this->staff)->get(route('reports.cost-codes'))->assertForbidden();
    }
}
