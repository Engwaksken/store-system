<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BoqItem;
use App\Models\CostCode;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\Store;
use App\Models\User;
use App\Services\IssueService;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;
use ZipArchive;

/** Phase 5: exports, in-app notifications, reminders, budget alerts, audit chain, imports, headers, query budgets. */
class ReportingAndSecurityTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ------------------------------------------------------------------ exports

    public function test_xlsx_and_pdf_report_exports(): void
    {
        $this->receive($this->cement, 10, 30000);
        $res = $this->actingAs($this->admin)->get(route('reports.stock', ['format' => 'xlsx']));
        $res->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'xlsx is a valid zip package');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('Cement', $sheet);
        $this->assertStringContainsString('<v>300000</v>', $sheet, 'money written as a number');

        $pdf = $this->actingAs($this->admin)->get(route('reports.stock', ['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_document_pdfs_respect_permissions(): void
    {
        $this->receive($this->cement, 10, 30000);
        $req = $this->approvedRequest(4);
        $issue = app(IssueService::class)->issue($this->store, $req, [$req->lines()->sole()->id => 4], 'Brian');
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->staff)->get(route('issues.pdf', $issue))->assertOk()->getContent());
        $this->actingAs($this->outsider)->get(route('issues.pdf', $issue))->assertForbidden();
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->store)->get(route('receipts.pdf', \App\Models\StockReceipt::first()))->assertOk()->getContent());

        $buyer = User::factory()->role(Role::Procurement)->create();
        $po = app(PurchasingService::class)->createOrder($buyer, ['supplier_id' => \App\Models\Supplier::create(['code' => 'S', 'name' => 'S'])->id, 'store_id' => 1],
            [['item_id' => $this->cement->id, 'qty' => 1, 'unit_price' => 1]]);
        $this->assertStringStartsWith('%PDF', $this->actingAs($buyer)->get(route('pos.pdf', $po))->assertOk()->getContent());
        $this->actingAs($this->staff)->get(route('pos.pdf', $po))->assertForbidden();
    }

    public function test_exports_obey_row_level_permissions(): void
    {
        $this->receive($this->cement, 20, 1000);
        $this->issue($this->approvedRequest(5), 5); // P-1, managed by $this->pm
        $other = Project::where('code', 'P-2')->sole();
        $other->members()->attach($this->staff->id);
        $r2 = $this->wf()->createDraft($this->staff, $other, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 3]]);
        $this->wf()->submit($this->staff, $r2);
        $this->wf()->pmDecide($this->otherPm, $r2, 'approved', null);
        $this->wf()->overallDecide($this->overall, $r2, 'approved', null);
        $this->issue($r2->fresh(), 3);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $res = $this->actingAs($this->pm)->get(route('reports.issued', ['format' => $format]))->assertOk();
            $body = $format === 'xlsx' ? $this->xlsxText($res) : ($format === 'csv' ? $res->streamedContent() : $res->getContent());
            if ($format !== 'pdf') { // PDF text is compressed; covered by the same query as csv/xlsx
                $this->assertStringContainsString('P-1', $body);
                $this->assertStringNotContainsString('P-2', $body, "PM export ({$format}) must not include other projects");
            }
        }
        $this->actingAs($this->staff)->get(route('reports.issued', ['format' => 'xlsx']))->assertForbidden();
    }

    private function issue(MaterialRequest $req, $qty)
    {
        return app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => $qty], 'X');
    }

    private function xlsxText($res): string
    {
        $zip = new ZipArchive();
        $zip->open($res->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }

    // ------------------------------------------------------------------ notifications

    public function test_in_app_notifications_bell_and_open(): void
    {
        $this->submittedRequest(5);
        $this->assertSame(1, $this->pm->unreadNotifications()->count());
        $this->actingAs($this->pm)->get(route('dashboard'))->assertSee('1 unread', false);
        $n = $this->pm->notifications()->first();
        $this->actingAs($this->outsider)->get(route('notifications.open', $n->id))->assertNotFound();
        $this->actingAs($this->pm)->get(route('notifications.open', $n->id))->assertRedirect(route('requests.show', MaterialRequest::first()));
        $this->assertSame(0, $this->pm->fresh()->unreadNotifications()->count());
        $this->actingAs($this->pm)->get(route('notifications.index'))->assertOk()->assertSee('Approval needed');
    }

    public function test_reminders_and_escalation_for_stale_approvals(): void
    {
        $req = $this->submittedRequest(5);
        Notification::fake();
        $this->travel(3)->days();
        Artisan::call('cms:reminders');
        Notification::assertSentTo($this->pm, \App\Notifications\WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'Reminder'));
        Notification::assertNotSentTo($this->admin, \App\Notifications\WorkflowAlert::class);

        $this->travel(3)->days();
        Artisan::call('cms:reminders');
        Notification::assertSentTo($this->admin, \App\Notifications\WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'Escalation') && str_contains($n->body, $this->pm->name));
        $this->assertSame('submitted', $req->fresh()->status->value);
    }

    public function test_budget_alerts_fire_once_per_level_and_rearm(): void
    {
        $code = CostCode::create(['code' => '03', 'name' => 'Concrete']);
        ProjectBudget::create(['project_id' => $this->project->id, 'cost_code_id' => $code->id, 'amount' => 100000]);
        $boq = BoqItem::create(['project_id' => $this->project->id, 'cost_code_id' => $code->id, 'ref' => '1', 'description' => 'x', 'unit' => 'm3', 'qty' => 1, 'rate' => 1, 'amount' => 1]);
        $this->receive($this->cement, 10, 10000);
        $req = $this->wf()->createDraft($this->staff, $this->project, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 10, 'boq_item_id' => $boq->id]]);
        $this->wf()->submit($this->staff, $req);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $issue = app(IssueService::class)->issue($this->store, $req->fresh(), [$req->lines()->sole()->id => 9], 'X'); // 90,000 = 90 %

        Notification::fake();
        Artisan::call('cms:budget-alerts');
        Notification::assertSentTo($this->pm, \App\Notifications\WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'reached 90%'));
        Notification::assertSentTo($this->accountant, \App\Notifications\WorkflowAlert::class);
        Notification::assertNotSentTo($this->otherPm, \App\Notifications\WorkflowAlert::class);
        $this->assertSame(1, DB::table('budget_alerts')->where('cost_code_id', $code->id)->count());

        Notification::fake();
        Artisan::call('cms:budget-alerts');
        Notification::assertNothingSent();

        app(IssueService::class)->issue($this->store, $req->fresh(), [$req->lines()->sole()->id => 1], 'X');
        ProjectBudget::where('cost_code_id', $code->id)->update(['amount' => 95000]); // now 100,000 used of 95,000
        Artisan::call('cms:budget-alerts');
        Notification::assertSentTo($this->pm, \App\Notifications\WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'OVER budget'));

        ProjectBudget::where('cost_code_id', $code->id)->update(['amount' => 1000000]); // back to ok → re-armed
        Artisan::call('cms:budget-alerts');
        $this->assertSame(0, DB::table('budget_alerts')->where('cost_code_id', $code->id)->count());
    }

    // ------------------------------------------------------------------ audit chain

    public function test_audit_chain_detects_edits_and_deletions(): void
    {
        $this->receive($this->cement, 5);
        $this->submittedRequest(2);
        $this->assertGreaterThanOrEqual(3, ActivityLog::count());
        $this->assertSame(0, Artisan::call('cms:verify-audit'));

        $victim = ActivityLog::orderBy('id')->skip(1)->first();
        DB::table('activity_logs')->where('id', $victim->id)->update(['description' => 'nothing to see here']);
        $this->assertSame(1, Artisan::call('cms:verify-audit'));
        $this->assertStringContainsString("#{$victim->id}", Artisan::output());
        // Restore the content AND the timestamp: MariaDB/MySQL without explicit_defaults_for_timestamp silently
        // re-stamps the first TIMESTAMP column on UPDATE, which the chain (correctly) also treats as tampering.
        DB::table('activity_logs')->where('id', $victim->id)->update(['description' => $victim->description, 'created_at' => $victim->getRawOriginal('created_at')]);
        $this->assertSame(0, Artisan::call('cms:verify-audit'), 'restoring the content heals the chain');

        DB::table('activity_logs')->where('id', $victim->id)->delete();
        $this->assertSame(1, Artisan::call('cms:verify-audit'));
    }

    public function test_deleting_the_newest_audit_rows_is_detected(): void
    {
        $this->submittedRequest(2);
        DB::table('activity_logs')->where('id', ActivityLog::max('id'))->delete();
        $this->assertSame(1, Artisan::call('cms:verify-audit'));
    }

    // ------------------------------------------------------------------ imports

    public function test_items_import_is_all_or_nothing(): void
    {
        $bad = UploadedFile::fake()->createWithContent('items.csv', "sku,name,category,unit,min_level\nNEW-1,Nails,Cement,bag,5\nCEM,Dup,Cement,bag,1\n");
        $this->actingAs($this->store)->post(route('items.import'), ['file' => $bad])->assertSessionHasErrors();
        $this->assertFalse(InventoryItem::where('sku', 'NEW-1')->exists(), 'nothing imported when any row fails');

        $good = UploadedFile::fake()->createWithContent('items.csv', "\xEF\xBB\xBFsku,name,category,unit,min_level\nNEW-1,Nails,cement,BAG,5\nNEW-2,Wire,Cement,bag,\n");
        $this->actingAs($this->store)->post(route('items.import'), ['file' => $good])->assertRedirect(route('items.index'));
        $this->assertSame(2, InventoryItem::whereIn('sku', ['NEW-1', 'NEW-2'])->count());
        $this->actingAs($this->staff)->post(route('items.import'), ['file' => $good])->assertForbidden();
    }

    public function test_boq_import(): void
    {
        CostCode::create(['code' => '03-CON', 'name' => 'Concrete']);
        $csv = UploadedFile::fake()->createWithContent('boq.csv', "ref,description,cost_code,unit,qty,rate,sku\n3.1,Slab,03-CON,m3,45,\"650,000\",\n3.2,Steel,03-CON,t,2,4200000,Y12\n");
        $this->actingAs($this->pm)->post(route('projects.boq.import', $this->project), ['file' => $csv])->assertRedirect();
        $this->assertSame('29250000.00', (string) BoqItem::where('ref', '3.1')->value('amount'));
        $this->assertSame($this->steel->id, BoqItem::where('ref', '3.2')->value('item_id'));

        $dup = UploadedFile::fake()->createWithContent('boq.csv', "ref,description,cost_code,unit,qty,rate\n3.1,Again,03-CON,m3,1,1\n");
        $this->actingAs($this->pm)->post(route('projects.boq.import', $this->project), ['file' => $dup])->assertSessionHasErrors();
        $this->actingAs($this->otherPm)->post(route('projects.boq.import', $this->project), ['file' => $dup])->assertForbidden();
    }

    // ------------------------------------------------------------------ hardening & performance

    public function test_security_headers_and_no_store_on_authenticated_pages(): void
    {
        $this->get(route('login'))->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
        $res = $this->actingAs($this->admin)->get(route('dashboard'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_dashboard_and_approvals_queries_do_not_grow_per_pending_request(): void
    {
        $this->receive($this->cement, 1000);
        $measure = function (User $u, string $route) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($u)->get(route($route))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            $this->wf()->pmDecide($this->pm, $this->submittedRequest(1), 'approved', null);
        }
        $small = [$measure($this->overall, 'dashboard'), $measure($this->overall, 'approvals.index')];
        foreach (range(1, 20) as $i) {
            $this->wf()->pmDecide($this->pm, $this->submittedRequest(1), 'approved', null);
        }
        $large = [$measure($this->overall, 'dashboard'), $measure($this->overall, 'approvals.index')];
        $this->assertLessThanOrEqual($small[0] + 2, $large[0], 'dashboard queries grew with pending requests');
        $this->assertLessThanOrEqual($small[1] + 2, $large[1], 'approvals page queries grew with pending requests');
    }
}
