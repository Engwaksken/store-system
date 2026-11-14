<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Claims list: view / edit / delete per claim, multiple selection with bulk approve, reject, pay, delete and export. */
class ClaimManagementTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function claim($amount = 50000, $by = null): ExpenseClaim
    {
        return app(ExpenseClaimService::class)->submit($by ?? $this->staff, [
            'project_id' => $this->project->id, 'category_id' => ExpenseCategory::firstOrCreate(['name' => 'Transport'])->id,
            'incurred_on' => now()->toDateString(), 'amount' => $amount, 'description' => 'Boda to site',
        ]);
    }

    private function form(ExpenseClaim $c, array $over = []): array
    {
        return array_merge(['project_id' => $c->project_id, 'category_id' => $c->category_id, 'incurred_on' => $c->incurred_on->toDateString(),
            'amount' => $c->amount, 'description' => $c->description], $over);
    }

    public function test_list_shows_selection_and_row_actions_by_permission(): void
    {
        $c = $this->claim();
        $this->actingAs($this->staff)->get(route('claims.index'))->assertOk()
            ->assertSee('data-bulk-scope', false)->assertSee('data-row-check', false)
            ->assertSee(route('claims.edit', $c), false)->assertSee(route('claims.destroy', $c), false);
        // The approver can view and decide but not edit someone else's claim.
        $this->actingAs($this->pm)->get(route('claims.index'))->assertOk()
            ->assertDontSee(route('claims.edit', $c), false)->assertSee('#claim-'.$c->id, false);
    }

    public function test_claimant_edits_until_approval_and_a_stale_approval_is_refused(): void
    {
        $c = $this->claim(50000);
        $seen = $c->updated_at->toIso8601String();
        $this->travel(5)->seconds();

        $this->actingAs($this->staff)->put(route('claims.update', $c), $this->form($c, ['amount' => 65000]))->assertSessionHasNoErrors();
        $this->assertEquals(65000, (float) $c->fresh()->amount);
        $this->assertTrue(ActivityLog::where('action', 'claim.updated')->exists());

        $this->actingAs($this->pm)->post(route('claims.decide', $c), ['decision' => 'approved', 'seen_at' => $seen])->assertSessionHasErrors();
        $this->assertSame('submitted', $c->fresh()->status);
        $this->actingAs($this->pm)->post(route('claims.decide', $c), ['decision' => 'approved', 'seen_at' => $c->fresh()->updated_at->toIso8601String()]);
        $this->assertSame('approved', $c->fresh()->status);

        $this->actingAs($this->staff)->get(route('claims.edit', $c))->assertForbidden();
        $this->actingAs($this->pm)->put(route('claims.update', $this->claim()), $this->form($c))->assertForbidden();
    }

    public function test_delete_only_before_approval_or_after_rejection_and_it_is_logged(): void
    {
        $c = $this->claim();
        $this->actingAs($this->pm)->delete(route('claims.destroy', $c))->assertForbidden();
        $this->actingAs($this->staff)->delete(route('claims.destroy', $c))->assertRedirect(route('claims.index'));
        $this->assertModelMissing($c);
        $this->assertTrue(ActivityLog::where('action', 'claim.deleted')->where('description', 'like', "%{$c->claim_no}%")->exists());

        $approved = $this->claim();
        app(ExpenseClaimService::class)->decide($this->pm, $approved, 'approved', null);
        $this->actingAs($this->admin)->delete(route('claims.destroy', $approved))->assertForbidden();
    }

    public function test_bulk_approve_pay_and_delete_process_what_qualifies_and_report_the_rest(): void
    {
        $a = $this->claim(10000);
        $b = $this->claim(20000);
        $own = $this->claim(30000, $this->pm);   // the PM cannot approve their own claim

        $this->actingAs($this->pm)->post(route('claims.bulk'), ['action' => 'approve', 'ids' => [$a->id, $b->id, $own->id]])
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, '2 claim(s) approved'))
            ->assertSessionHasErrors('bulk');
        $this->assertSame(['approved', 'approved', 'submitted'], [$a->fresh()->status, $b->fresh()->status, $own->fresh()->status]);

        $this->actingAs($this->accountant)->post(route('claims.bulk'), ['action' => 'pay', 'ids' => [$a->id, $b->id], 'payment_mode' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Expense::whereIn('expense_claim_id', [$a->id, $b->id])->count());

        $this->actingAs($this->pm)->post(route('claims.bulk'), ['action' => 'reject', 'ids' => [$own->id]])->assertSessionHasErrors('reason');
        $this->actingAs($this->pm)->post(route('claims.bulk'), ['action' => 'delete', 'ids' => [$own->id, $a->id]])
            ->assertSessionHasErrors('bulk');   // the paid claim is skipped
        $this->assertModelMissing($own);
        $this->assertModelExists($a);
    }

    public function test_export_of_selected_claims_only(): void
    {
        $a = $this->claim(10000);
        $this->claim(20000);
        $csv = $this->actingAs($this->staff)->get(route('claims.index', ['format' => 'csv', 'ids' => [$a->id]]))->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);   // header, the selected claim, total
        $this->assertStringContainsString($a->claim_no, $lines[1]);
    }

    public function test_rejected_claim_is_corrected_and_resubmitted(): void
    {
        $c = $this->claim(50000);
        app(ExpenseClaimService::class)->decide($this->pm, $c, 'rejected', 'Receipt missing');

        $this->actingAs($this->staff)->get(route('claims.edit', $c))->assertOk()->assertSee('Receipt missing')->assertSee('Save &amp; resubmit', false);
        $this->actingAs($this->pm)->get(route('claims.edit', $c))->assertForbidden();

        $this->actingAs($this->staff)->put(route('claims.update', $c), $this->form($c, ['amount' => 45000]))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('submitted', $c->status);
        $this->assertNull($c->decision_reason);
        $this->assertNull($c->approver_id);
        $log = ActivityLog::where('action', 'claim.resubmitted')->sole();
        $this->assertSame('Receipt missing', $log->properties['before']['decision_reason'] ?? null);

        app(ExpenseClaimService::class)->decide($this->pm, $c, 'approved', null);
        $this->assertSame('approved', $c->fresh()->status);
        $this->actingAs($this->staff)->get(route('claims.edit', $c))->assertForbidden();
    }
}
