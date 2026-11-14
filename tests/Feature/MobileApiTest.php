<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Services\ExpenseClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Mobile API v1 (docs/API.md): token auth, the same permissions and workflow rules as the web app. */
class MobileApiTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function tokenFor(User $u): array
    {
        // The test app is shared between requests and the Sanctum guard caches the first user it resolved;
        // forget it when switching users (a real server builds a fresh app per request).
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$u->createToken('test')->plainTextToken];
    }

    // ---------------------------------------------------------------- auth

    public function test_login_issues_a_token_and_me_returns_the_user_with_permissions(): void
    {
        $this->staff->forceFill(['password' => 'Secret123'])->save();
        $res = $this->postJson('/api/v1/auth/login', ['email' => $this->staff->email, 'password' => 'Secret123', 'device_name' => 'Pixel'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'role', 'role_label', 'currency', 'can' => ['approve_requests', 'create_request']]]);
        $this->assertTrue($res->json('user.can.create_request'));
        $this->assertFalse($res->json('user.can.approve_requests'));

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$res->json('token')])->assertOk()->assertJsonPath('user.email', $this->staff->email);
    }

    public function test_bad_credentials_unauthenticated_and_deactivated(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->staff->email, 'password' => 'wrong', 'device_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->get('/api/v1/me')->assertStatus(401); // no Accept header: still JSON, never a redirect

        $headers = $this->tokenFor($this->staff);
        $this->staff->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/me', $headers)->assertStatus(403)->assertJsonPath('code', 'deactivated');
        $this->assertSame(0, $this->staff->tokens()->count(), 'token revoked');
    }

    public function test_temporary_password_must_be_changed_before_anything_else(): void
    {
        $this->staff->forceFill(['password' => 'Temp1234', 'must_change_password' => true])->save();
        $headers = $this->tokenFor($this->staff);
        $this->getJson('/api/v1/dashboard', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('user.must_change_password', true);

        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'Temp1234', 'password' => 'Temp1234', 'password_confirmation' => 'Temp1234'], $headers)
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'Temp1234', 'password' => 'NewPass99', 'password_confirmation' => 'NewPass99'], $headers)
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/v1/dashboard', $headers)->assertOk();
    }

    public function test_logout_revokes_the_token(): void
    {
        $headers = $this->tokenFor($this->staff);
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
        $this->assertSame(0, $this->staff->tokens()->count());
    }

    // ---------------------------------------------------------------- requests and approvals

    public function test_staff_creates_and_submits_a_request_and_pm_approves_with_reduced_qty(): void
    {
        $res = $this->postJson('/api/v1/requests', [
            'project_id' => $this->project->id, 'purpose' => 'Slab', 'submit' => true,
            'lines' => [['item_id' => $this->cement->id, 'requested_qty' => '10']],
        ], $this->tokenFor($this->staff))->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.lines.0.requested_qty', '10.000');
        $id = $res->json('data.id');
        $lineId = $res->json('data.lines.0.id');

        $pm = $this->tokenFor($this->pm);
        $this->getJson('/api/v1/approvals', $pm)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/requests/{$id}", $pm)->assertOk()->assertJsonPath('data.can.decide', true)->assertJsonPath('data.can.decision_step', 'pm');

        $this->postJson("/api/v1/requests/{$id}/decision", ['decision' => 'approved', 'qty' => [$lineId => '12'], 'version' => 1], $pm)->assertStatus(409);
        $this->postJson("/api/v1/requests/{$id}/decision", ['decision' => 'approved', 'qty' => [$lineId => '8'], 'version' => 2], $pm)->assertStatus(409);
        $this->postJson("/api/v1/requests/{$id}/decision", ['decision' => 'approved', 'qty' => [$lineId => '8'], 'version' => 1], $pm)
            ->assertOk()->assertJsonPath('data.status', 'pm_approved')->assertJsonPath('data.lines.0.pm_approved_qty', '8.000')
            ->assertJsonPath('data.can.decide', false);

        // Overall manager sees it next; another PM never could.
        $this->getJson("/api/v1/requests/{$id}", $this->tokenFor($this->overall))->assertJsonPath('data.can.decision_step', 'overall');
        $this->postJson("/api/v1/requests/{$id}/decision", ['decision' => 'approved'], $this->tokenFor($this->otherPm))->assertStatus(403);
    }

    public function test_reject_needs_a_reason_and_outsiders_cannot_see_requests(): void
    {
        $req = $this->submittedRequest(5);
        $this->postJson("/api/v1/requests/{$req->id}/decision", ['decision' => 'rejected'], $this->tokenFor($this->pm))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->getJson("/api/v1/requests/{$req->id}", $this->tokenFor($this->outsider))->assertStatus(403);
        $this->getJson('/api/v1/requests/999999', $this->tokenFor($this->pm))->assertStatus(404)->assertJsonPath('message', 'Not found.');
        $this->getJson('/api/v1/approvals', $this->tokenFor($this->staff))->assertStatus(403);
    }

    public function test_requester_cancels_and_lists_are_paginated(): void
    {
        $req = $this->submittedRequest(5);
        $this->getJson('/api/v1/requests?per_page=1', $this->tokenFor($this->staff))->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
        $this->postJson("/api/v1/requests/{$req->id}/cancel", [], $this->tokenFor($this->staff))->assertOk()->assertJsonPath('data.status', RequestStatus::Cancelled->value);
    }

    // ---------------------------------------------------------------- stock

    public function test_items_show_stock_and_hide_cost_from_staff(): void
    {
        $this->receive($this->cement, 50, 30000);
        $this->getJson('/api/v1/items?q=CEM', $this->tokenFor($this->staff))->assertOk()
            ->assertJsonPath('data.0.on_hand', '50.000')->assertJsonMissingPath('data.0.avg_cost');
        $this->getJson("/api/v1/items/{$this->cement->id}", $this->tokenFor($this->store))->assertOk()
            ->assertJsonPath('data.avg_cost', '30000.00')->assertJsonPath('data.balances.0.on_hand', '50.000');
        $this->getJson('/api/v1/stores', $this->tokenFor($this->staff))->assertOk()->assertJsonStructure(['data' => [['id', 'code', 'name']]]);
    }

    // ---------------------------------------------------------------- claims

    public function test_claim_submitted_with_receipt_and_decided_with_seen_at(): void
    {
        Storage::fake('local');
        $cat = ExpenseCategory::create(['name' => 'Transport', 'is_active' => true]);
        $res = $this->post('/api/v1/claims', [
            'project_id' => $this->project->id, 'category_id' => $cat->id, 'incurred_on' => now()->toDateString(),
            'amount' => '45000', 'description' => 'Boda to site', 'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], $this->tokenFor($this->staff))->assertCreated()->assertJsonPath('data.amount', '45000.00')->assertJsonPath('data.has_receipt', true);
        $id = $res->json('data.id');

        $pm = $this->tokenFor($this->pm);
        $claim = $this->getJson("/api/v1/claims/{$id}", $pm)->assertOk()->assertJsonPath('data.can.decide', true);
        $this->get("/api/v1/claims/{$id}/receipt", $pm)->assertOk();

        ExpenseClaim::whereKey($id)->update(['updated_at' => now()->addMinute()]);
        $this->postJson("/api/v1/claims/{$id}/decision", ['decision' => 'approved', 'seen_at' => $claim->json('data.updated_at')], $pm)->assertStatus(409);
        $fresh = $this->getJson("/api/v1/claims/{$id}", $pm)->json('data.updated_at');
        $this->postJson("/api/v1/claims/{$id}/decision", ['decision' => 'approved', 'seen_at' => $fresh], $pm)->assertOk()->assertJsonPath('data.status', 'approved');

        // The claimant cannot decide their own claim.
        $own = app(ExpenseClaimService::class)->submit($this->pm, ['project_id' => $this->project->id, 'category_id' => $cat->id,
            'incurred_on' => now()->toDateString(), 'amount' => 10, 'description' => 'x']);
        $this->postJson("/api/v1/claims/{$own->id}/decision", ['decision' => 'approved'], $pm)->assertStatus(403);
    }

    // ---------------------------------------------------------------- dashboard + notifications

    public function test_dashboard_and_notifications_with_targets(): void
    {
        $req = $this->submittedRequest(5); // notifies the PM
        $pm = $this->tokenFor($this->pm);
        $dash = $this->getJson('/api/v1/dashboard', $pm)->assertOk()->assertJsonPath('awaiting_me.0.id', $req->id);
        $this->assertSame(1, collect($dash->json('cards'))->firstWhere('key', 'awaiting_me')['value']);

        $list = $this->getJson('/api/v1/notifications', $pm)->assertOk();
        $n = collect($list->json('data'))->firstWhere('target.type', 'request');
        $this->assertNotNull($n, 'notification links to the request');
        $this->assertSame($req->id, $n['target']['id']);
        $this->postJson("/api/v1/notifications/{$n['id']}/read", [], $pm)->assertNoContent();
        $this->postJson('/api/v1/notifications/read-all', [], $pm)->assertNoContent();
        $this->getJson('/api/v1/dashboard', $pm)->assertJsonPath('unread_notifications', 0);
    }
}
