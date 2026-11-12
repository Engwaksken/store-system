<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\BaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Seeds the demo data set and renders every screen for every role: catches template and permission regressions. */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_renders_for_every_role_with_demo_data(): void
    {
        $this->seed([BaseSeeder::class, DemoSeeder::class]);
        $pages = ['dashboard', 'projects.index', 'requests.index', 'issues.index', 'items.index', 'receipts.index', 'catalog.setup', 'approvals.index',
            'expenses.index', 'expenses.create', 'reports.index', 'reports.stock', 'reports.movements', 'reports.pending', 'reports.issued', 'reports.expenses',
            'reports.project-cost', 'users.index', 'users.create', 'activity.index', 'settings.edit', 'projects.create', 'items.create', 'receipts.create',
            'adjustments.create', 'requests.create', 'password.change'];
        $detail = [
            fn () => route('projects.show', Project::first()),
            fn () => route('requests.show', \App\Models\MaterialRequest::first()),
            fn () => route('issues.show', \App\Models\MaterialIssue::first()),
            fn () => route('items.show', \App\Models\InventoryItem::first()),
            fn () => route('receipts.show', \App\Models\StockReceipt::first()),
        ];
        foreach (['owner', 'overall', 'pm1', 'store', 'accounts', 'staff1'] as $who) {
            $user = User::where('email', "{$who}@demo.test")->sole();
            foreach ($pages as $page) {
                $status = $this->actingAs($user)->get(route($page))->status();
                $this->assertContains($status, [200, 403], "{$who} {$page} returned {$status}");
            }
            foreach ($detail as $url) {
                $status = $this->actingAs($user)->get($url())->status();
                $this->assertContains($status, [200, 403], "{$who} {$url()} returned {$status}");
            }
        }
        // Owner sees everything.
        $owner = User::where('email', 'owner@demo.test')->sole();
        foreach ($pages as $page) {
            $this->actingAs($owner)->get(route($page))->assertOk();
        }
    }
}
