<?php

namespace Tests\Feature;

use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\BaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** Every list page: period / project / store / search filters, pagination and CSV / PDF export (App\Support\ListPage). */
class ListPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Route names of list pages built with ListPage + <x-list-filters>. */
    public const PAGES = [
        'requests.index', 'approvals.index',
        'projects.index', 'issues.index', 'items.index', 'stores.index', 'receipts.index', 'transfers.index',
        'returns.index', 'counts.index', 'prs.index', 'pos.index', 'suppliers.index', 'invoices.index',
        'claims.index', 'expenses.index', 'cost-codes.index', 'users.index', 'notifications.index', 'activity.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([BaseSeeder::class, DemoSeeder::class]);
    }

    public function test_every_list_page_filters_and_exports_for_the_owner(): void
    {
        $owner = User::where('email', 'owner@demo.test')->sole();
        $filters = [
            'period' => 'this_year', 'project_id' => Project::value('id'), 'store_id' => Store::DEFAULT_ID, 'q' => 'a',
        ];
        foreach (self::PAGES as $page) {
            $this->actingAs($owner)->get(route($page))->assertOk()->assertSee('data-list-filters', false);
            $this->actingAs($owner)->get(route($page, $filters))->assertOk();
            $this->actingAs($owner)->get(route($page, ['period' => 'custom', 'from' => now()->subYear()->toDateString(), 'to' => now()->toDateString(), 'page' => 2]))->assertOk();

            $csv = $this->actingAs($owner)->get(route($page, ['format' => 'csv']));
            $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent(), "{$page} CSV");

            $pdf = $this->actingAs($owner)->get(route($page, ['format' => 'pdf'] + $filters));
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent(), "{$page} PDF");
        }
    }

    public function test_filters_narrow_the_rows_and_the_export_matches(): void
    {
        $owner = User::where('email', 'owner@demo.test')->sole();
        $project = Project::orderBy('id')->first();
        $all = MaterialRequest::count();
        $mine = MaterialRequest::where('project_id', $project->id)->count();
        $this->assertGreaterThan($mine, $all, 'demo data has requests in more than one project');

        $csv = $this->actingAs($owner)->get(route('requests.index', ['format' => 'csv', 'project_id' => $project->id]))->streamedContent();
        $this->assertSame($mine + 1, count(array_filter(explode("\n", trim($csv)))), 'header + one line per request of that project');

        $future = $this->actingAs($owner)->get(route('requests.index', ['format' => 'csv', 'period' => 'custom', 'from' => now()->addYear()->toDateString()]))->streamedContent();
        $this->assertSame(1, count(array_filter(explode("\n", trim($future)))), 'only the header');
    }

    public function test_scoped_users_only_export_what_they_may_see(): void
    {
        $staff = User::where('email', 'staff1@demo.test')->sole();
        $visible = MaterialRequest::visibleTo($staff)->count();
        $csv = $this->actingAs($staff)->get(route('requests.index', ['format' => 'csv']))->streamedContent();
        $this->assertSame($visible + 1, count(array_filter(explode("\n", trim($csv)))));
    }

    public function test_approvals_export_contains_only_requests_this_user_may_decide(): void
    {
        $pm = User::where('email', 'pm1@demo.test')->sole();
        $mine = MaterialRequest::whereIn('status', ['submitted', 'pm_approved'])->get()
            ->filter(fn ($r) => \Illuminate\Support\Facades\Gate::forUser($pm)->allows('pm-decide', $r))->pluck('request_no');
        $csv = $this->actingAs($pm)->get(route('approvals.index', ['format' => 'csv']))->streamedContent();
        $lines = array_values(array_filter(explode("
", trim($csv))));
        $this->assertCount($mine->count() + 1, $lines);
        foreach (array_slice($lines, 1) as $line) {
            $this->assertContains(str_getcsv($line)[0], $mine->all());
        }
    }

    public function test_report_pages_accept_period_presets(): void
    {
        $owner = User::where('email', 'owner@demo.test')->sole();
        $this->actingAs($owner)->get(route('reports.expenses', ['period' => 'this_year']))->assertOk()->assertSee('data-period-select', false);
        $all = $this->actingAs($owner)->get(route('reports.movements', ['format' => 'csv']))->streamedContent();
        $none = $this->actingAs($owner)->get(route('reports.movements', ['format' => 'csv', 'period' => 'custom', 'from' => now()->addYear()->toDateString()]))->streamedContent();
        $this->assertGreaterThan(count(explode("
", trim($none))), count(explode("
", trim($all))));
        $this->actingAs($owner)->get(route('reports.movements', ['format' => 'pdf', 'period' => 'last_month']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
