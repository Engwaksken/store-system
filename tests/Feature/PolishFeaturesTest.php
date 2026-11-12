<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockReceipt;
use App\Notifications\WorkflowAlert;
use App\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Backlog B-24..B-26: approver e-mail alerts, opening-balance CSV import, add-line forms. */
class PolishFeaturesTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_each_step_alerts_the_next_person_in_the_chain(): void
    {
        Notification::fake();
        $this->receive($this->cement, 50);

        $req = $this->submittedRequest(5);
        Notification::assertSentTo($this->pm, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Approval needed'));
        Notification::assertNotSentTo($this->otherPm, WorkflowAlert::class);

        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        Notification::assertSentTo($this->overall, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Final approval needed'));

        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        Notification::assertSentTo($this->store, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Ready to issue'));
        Notification::assertSentTo($this->staff, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Approved'));

        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 5], 'X');
        Notification::assertSentTo($this->staff, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Materials issued'));
    }

    public function test_rejection_alerts_requester_with_reason(): void
    {
        Notification::fake();
        $req = $this->submittedRequest(5);
        $this->wf()->pmDecide($this->pm, $req, 'rejected', 'Wrong item');
        Notification::assertSentTo($this->staff, WorkflowAlert::class, fn ($n) => str_contains($n->body, 'Wrong item'));
    }

    public function test_pm_self_request_alerts_admin_substitute(): void
    {
        Notification::fake();
        $this->submittedRequest(5, null, $this->pm);
        Notification::assertSentTo($this->admin, WorkflowAlert::class);
        Notification::assertNotSentTo($this->pm, WorkflowAlert::class);
    }

    public function test_failed_workflow_step_sends_no_alert(): void
    {
        Notification::fake();
        $req = $this->submittedRequest(5);
        try {
            $this->wf()->pmDecide($this->pm, $req, 'approved', null, [$req->lines->first()->id => 99]);
        } catch (\Throwable) {
        }
        Notification::assertNotSentTo($this->overall, WorkflowAlert::class);
    }

    public function test_opening_balance_csv_template_and_import(): void
    {
        $csv = $this->actingAs($this->store)->get(route('receipts.opening-template'))->assertOk()->streamedContent();
        $this->assertStringContainsString('sku,qty,unit_cost', $csv);
        $this->assertStringContainsString('CEM', $csv);

        $file = UploadedFile::fake()->createWithContent('count.csv', "\xEF\xBB\xBFsku,qty,unit_cost,item_name_for_reference\nCEM,40,\"30,000\",Cement\nY12,,,Steel\n");
        $this->actingAs($this->store)->post(route('receipts.opening-import'), ['file' => $file, 'received_on' => now()->toDateString()])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('40.000', $this->onHand($this->cement));
        $this->assertSame('0.000', $this->onHand($this->steel));
        $this->assertSame('opening', StockReceipt::sole()->type);
        $this->assertSame('30000.0000', (string) InventoryItem::find($this->cement->id)->avg_cost);
    }

    public function test_csv_import_with_bad_row_imports_nothing(): void
    {
        $file = UploadedFile::fake()->createWithContent('count.csv', "sku,qty,unit_cost\nCEM,10,1000\nNOPE,5,100\nY12,-1,100\n");
        $this->actingAs($this->store)->post(route('receipts.opening-import'), ['file' => $file, 'received_on' => now()->toDateString()])
            ->assertSessionHasErrors();
        $this->assertSame('0.000', $this->onHand($this->cement));
        $this->assertSame(0, StockReceipt::count());
    }

    public function test_csv_import_is_storekeeper_or_admin_only(): void
    {
        $file = UploadedFile::fake()->createWithContent('c.csv', "sku,qty,unit_cost\nCEM,10,1000\n");
        $this->actingAs($this->accountant)->post(route('receipts.opening-import'), ['file' => $file, 'received_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->staff)->get(route('receipts.opening-template'))->assertForbidden();
    }

    public function test_forms_offer_add_line_button(): void
    {
        $this->actingAs($this->staff)->get(route('requests.create'))->assertOk()->assertSee('data-add-line="#request-lines"', false);
        $this->actingAs($this->store)->get(route('receipts.create', ['type' => 'opening']))->assertOk()
            ->assertSee('data-add-line="#lines"', false)->assertSee('Import opening balances');
    }
}
