<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\ProjectCostService;
use Database\Seeders\BaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** UAT §Tests: direct HTTP authorization, project scoping, expenses, auth, and a render smoke test for every role. */
class AccessControlTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->post(route('receipts.store'))->assertRedirect(route('login'));
    }

    public function test_unauthorized_direct_requests_are_rejected_regardless_of_menus(): void
    {
        $checks = [
            [$this->staff, 'get', route('users.index')],
            [$this->staff, 'post', route('users.store')],
            [$this->staff, 'get', route('receipts.create')],
            [$this->staff, 'post', route('receipts.store')],
            [$this->staff, 'post', route('adjustments.store')],
            [$this->staff, 'get', route('expenses.index')],
            [$this->staff, 'post', route('expenses.store')],
            [$this->staff, 'post', route('projects.store')],
            [$this->staff, 'get', route('activity.index')],
            [$this->staff, 'get', route('reports.project-cost')],
            [$this->accountant, 'post', route('receipts.store')],
            [$this->accountant, 'post', route('requests.store')],
            [$this->store, 'post', route('expenses.store')],
            [$this->store, 'get', route('reports.project-cost')],
            [$this->pm, 'post', route('items.store')],
            [$this->pm, 'get', route('settings.edit')],
            [$this->overall, 'post', route('receipts.store')],
        ];
        foreach ($checks as [$user, $method, $url]) {
            $this->actingAs($user)->{$method}($url, [])->assertForbidden();
        }
    }

    public function test_project_visibility_is_role_scoped(): void
    {
        $other = Project::where('code', 'P-2')->first();
        $this->actingAs($this->staff)->get(route('projects.show', $other))->assertForbidden();
        $this->actingAs($this->staff)->get(route('projects.show', $this->project))->assertOk();
        $this->actingAs($this->pm)->get(route('projects.show', $other))->assertForbidden();
        $this->actingAs($this->overall)->get(route('projects.show', $other))->assertOk();
        $this->actingAs($this->staff)->get(route('projects.index'))->assertSee('P-1')->assertDontSee('P-2');
    }

    public function test_requests_are_visible_only_to_permitted_users(): void
    {
        $req = $this->submittedRequest();
        $this->actingAs($this->outsider)->get(route('requests.show', $req))->assertForbidden();
        $this->actingAs($this->otherPm)->get(route('requests.show', $req))->assertForbidden();
        $this->actingAs($this->store)->get(route('requests.show', $req))->assertForbidden(); // not yet approved
        $this->actingAs($this->pm)->get(route('requests.show', $req))->assertOk();
        $this->actingAs($this->staff)->get(route('requests.show', $req))->assertOk();
    }

    public function test_expense_access_is_permission_controlled_and_scoped(): void
    {
        Storage::fake('local');
        $cat = ExpenseCategory::create(['name' => 'Labour']);
        $other = Project::where('code', 'P-2')->first();
        $this->actingAs($this->accountant)->post(route('expenses.store'), [
            'project_id' => $this->project->id, 'category_id' => $cat->id, 'incurred_on' => now()->toDateString(),
            'amount' => 50000, 'payment_mode' => 'cash', 'description' => 'Casuals', 'proof' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $mine = Expense::sole();
        $theirs = Expense::create(['project_id' => $other->id, 'category_id' => $cat->id, 'incurred_on' => now(), 'amount' => 70000, 'payment_mode' => 'cash', 'description' => 'Other', 'recorded_by' => $this->accountant->id]);

        $this->actingAs($this->pm)->get(route('expenses.index'))->assertOk()->assertSee('Casuals')->assertDontSee('Other');
        $this->actingAs($this->pm)->get(route('expenses.proof', $mine))->assertOk();
        $this->actingAs($this->otherPm)->get(route('expenses.proof', $mine))->assertForbidden();
        $this->actingAs($this->staff)->get(route('expenses.proof', $mine))->assertForbidden();
        $this->actingAs($this->pm)->get(route('reports.expenses'))->assertOk()->assertSee('P-1')->assertDontSee('Other project');
        $this->assertNotNull($theirs);
    }

    public function test_store_purchases_are_not_counted_as_project_cost(): void
    {
        $cat = ExpenseCategory::create(['name' => 'Labour']);
        $this->receive($this->cement, 100, 30000); // UGX 3,000,000 into store: NOT project cost
        $req = $this->approvedRequest(10);
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 10], 'X'); // 300,000 consumed
        Expense::create(['project_id' => $this->project->id, 'category_id' => $cat->id, 'incurred_on' => now(), 'amount' => 50000, 'payment_mode' => 'cash', 'description' => 'Casuals', 'recorded_by' => $this->accountant->id]);

        $row = app(ProjectCostService::class)->summary(collect([$this->project]))->first();
        $this->assertSame('300000.00', $row->materials);
        $this->assertSame('50000.00', $row->expenses);
        $this->assertSame('350000.00', $row->total);
        $this->assertSame('650000.00', $row->remaining);

        $csv = $this->actingAs($this->accountant)->get(route('reports.project-cost', ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('P-1', $csv);
        $this->assertStringContainsString('350000.00', $csv);
    }

    public function test_inactive_user_cannot_log_in_and_is_logged_out(): void
    {
        $u = User::factory()->create(['password' => 'Secret123', 'is_active' => false]);
        $this->post(route('login'), ['email' => $u->email, 'password' => 'Secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($u)->get('/')->assertRedirect(route('login'));
    }

    public function test_login_and_forced_password_change(): void
    {
        $u = User::factory()->create(['password' => 'Secret123', 'must_change_password' => true]);
        $this->post(route('login'), ['email' => $u->email, 'password' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->get('/')->assertRedirect(route('password.change'));
        $this->put(route('password.change.update'), ['current_password' => 'Secret123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertRedirect(route('dashboard'));
        $this->get('/')->assertOk();
    }

    public function test_password_reset_link_flow(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->post(route('password.email'), ['email' => $this->staff->email])->assertSessionHas('status');
        \Illuminate\Support\Facades\Notification::assertSentTo($this->staff, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) {
            $this->post(route('password.update'), ['token' => $n->token, 'email' => $this->staff->email, 'password' => 'Reset1234', 'password_confirmation' => 'Reset1234'])->assertRedirect(route('login'));

            return true;
        });
        $this->post(route('login'), ['email' => $this->staff->email, 'password' => 'Reset1234'])->assertRedirect(route('dashboard'));
    }
}
