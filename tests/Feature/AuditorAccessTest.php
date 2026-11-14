<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The Auditor role can read everything that is reportable and change nothing. */
class AuditorAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Route parameter name => model class used to fill it from the demo data. */
    private const BINDINGS = [
        'project' => \App\Models\Project::class, 'store' => \App\Models\Store::class, 'transfer' => \App\Models\StockTransfer::class,
        'issue' => \App\Models\MaterialIssue::class, 'return' => \App\Models\StockReturn::class, 'count' => \App\Models\StockCount::class,
        'materialRequest' => \App\Models\MaterialRequest::class, 'item' => \App\Models\InventoryItem::class, 'receipt' => \App\Models\StockReceipt::class,
        'user' => User::class, 'expense' => \App\Models\Expense::class, 'pr' => \App\Models\PurchaseRequisition::class, 'quote' => \App\Models\SupplierQuote::class,
        'po' => \App\Models\PurchaseOrder::class, 'invoice' => \App\Models\PurchaseInvoice::class, 'supplier' => \App\Models\Supplier::class,
        'claim' => \App\Models\ExpenseClaim::class, 'boq' => \App\Models\BoqItem::class, 'milestone' => \App\Models\ProjectMilestone::class,
        'costCode' => \App\Models\CostCode::class, 'category' => \App\Models\InventoryCategory::class, 'unit' => \App\Models\InventoryUnit::class,
        'expenseCategory' => \App\Models\ExpenseCategory::class, 'id' => \App\Models\InventoryCategory::class,
    ];

    /** Route parameters that are fixed words rather than records. */
    private const LITERALS = ['type' => 'categories'];

    /** Mutating routes every signed-in user may use on their own account. */
    /** Routes that change only the signed-in user's own account (password, own notifications, own push devices). */
    private const PERSONAL = ['logout', 'password.change.update', 'notifications.read-all',
        'notifications.settings.update', 'notifications.test', 'push.subscribe', 'push.unsubscribe'];

    public function test_auditor_reads_everything_and_writes_nothing(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([BaseSeeder::class, DemoSeeder::class]);
        $auditor = User::where('email', 'auditor@demo.test')->sole();

        foreach (['dashboard', 'projects.index', 'requests.index', 'issues.index', 'items.index', 'receipts.index', 'stores.index', 'transfers.index',
            'returns.index', 'counts.index', 'prs.index', 'pos.index', 'suppliers.index', 'invoices.index', 'claims.index', 'expenses.index',
            'cost-codes.index', 'activity.index', 'reports.index', 'reports.stock', 'reports.movements', 'reports.issued', 'reports.expenses',
            'reports.project-cost', 'reports.cost-codes', 'reports.boq', 'reports.commitments'] as $page) {
            $this->actingAs($auditor)->get(route($page))->assertOk();
        }
        $this->actingAs($auditor)->get(route('projects.controls', \App\Models\Project::first()))->assertOk()->assertDontSee('Save budgets');
        $this->actingAs($auditor)->get(route('activity.index', ['format' => 'xlsx']))->assertOk();
        $this->actingAs($auditor)->get(route('pos.show', \App\Models\PurchaseOrder::first()))->assertOk()->assertDontSee('Approve PO');

        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $methods = array_diff($route->methods(), ['GET', 'HEAD']);
            if (! $methods || ! in_array('auth', $route->gatherMiddleware(), true) || in_array($route->getName(), self::PERSONAL, true)) {
                continue;
            }
            $params = [];
            foreach ($route->parameterNames() as $name) {
                if (isset(self::LITERALS[$name])) {
                    $params[$name] = self::LITERALS[$name];

                    continue;
                }
                $class = self::BINDINGS[$name] ?? null;
                $this->assertNotNull($class, "No test binding for route parameter {{$name}} in {$route->uri()}");
                $params[$name] = $class::query()->value('id');
            }
            $method = strtolower(reset($methods));
            $status = $this->actingAs($auditor)->{$method}(route($route->getName(), $params), [])->status();
            $this->assertSame(403, $status, "Auditor got {$status} on {$method} {$route->uri()}");
            $checked++;
        }
        $this->assertGreaterThan(60, $checked, 'every mutating route was exercised');
    }
}
