<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Mobile API v1: chart summaries (`GET /reports`) and self-service profile (`PUT /me`). */
class MobileReportsAndProfileTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function tokenFor(User $u): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$u->createToken('test')->plainTextToken];
    }

    private function chart(array $json, string $key): ?array
    {
        return collect($json['charts'])->firstWhere('key', $key);
    }

    // ---------------------------------------------------------------- reports

    public function test_reports_summarise_stock_requests_and_issues_for_a_manager(): void
    {
        $this->receive($this->cement, 20, 1000);
        $req = $this->approvedRequest(5);
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 5], 'Site');

        $json = $this->getJson('/api/v1/reports?days=30', $this->tokenFor($this->admin))->assertOk()
            ->assertJsonPath('period.days', 30)->json();

        // Cement has 15 on hand (above minimum 5); steel was never received, so it is out of stock.
        $status = collect($this->chart($json, 'stock_status')['series'])->pluck('value', 'label');
        $this->assertSame(['In stock' => 1, 'Low stock' => 0, 'Out of stock' => 1], $status->all());

        $this->assertSame('15000.00', collect($json['kpis'])->firstWhere('key', 'stock_value')['value']);
        $this->assertSame('5.000', $this->chart($json, 'top_issued_items')['series'][0]['value']);
        $this->assertSame('P-1', $this->chart($json, 'issued_value_by_project')['series'][0]['label']);
        $this->assertSame(1, collect($this->chart($json, 'requests_by_status')['series'])->sum('value'));
        $this->assertSame(1, collect($this->chart($json, 'requests_over_time')['series'])->sum('value'));
    }

    public function test_staff_see_only_their_requests_and_no_values_or_issue_charts(): void
    {
        $this->submittedRequest(3);
        $this->submittedRequest(2, null, $this->pm); // not visible to staff

        $json = $this->getJson('/api/v1/reports?days=7', $this->tokenFor($this->staff))->assertOk()->json();

        $this->assertSame(1, collect($this->chart($json, 'requests_by_status')['series'])->sum('value'));
        $this->assertNull(collect($json['kpis'])->firstWhere('key', 'stock_value'));
        $this->assertNull($this->chart($json, 'stock_value_by_category'));
        $this->assertNotNull($this->chart($json, 'items_by_category'));
        $this->assertNull($this->chart($json, 'top_issued_items'));
        $this->assertNull($this->chart($json, 'issued_value_by_project'));
        $this->assertCount(7, $this->chart($json, 'requests_over_time')['series']);
    }

    public function test_unknown_period_falls_back_to_thirty_days_and_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/reports?days=5000', $this->tokenFor($this->admin))->assertOk()->assertJsonPath('period.days', 30);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- profile

    public function test_user_updates_own_name_and_phone(): void
    {
        $as = $this->tokenFor($this->staff);

        $this->putJson('/api/v1/me', ['name' => '  Jane Doe ', 'email' => $this->staff->email, 'phone' => '+256 700 000000'], $as)
            ->assertOk()->assertJsonPath('user.name', 'Jane Doe')->assertJsonPath('user.phone', '+256 700 000000');

        $this->assertSame('Jane Doe', $this->staff->fresh()->name);
        $this->assertTrue(ActivityLog::where('action', 'auth.profile_updated')->exists());

        $this->putJson('/api/v1/me', ['name' => 'Jane Doe', 'email' => $this->staff->email, 'phone' => ''], $as)
            ->assertOk()->assertJsonPath('user.phone', null);
    }

    public function test_changing_email_needs_the_current_password_and_a_free_address(): void
    {
        $this->staff->forceFill(['password' => Hash::make('Secret123')])->save();
        $as = $this->tokenFor($this->staff);

        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => 'new@example.com'], $as)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => 'new@example.com', 'current_password' => 'wrong'], $as)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => $this->admin->email, 'current_password' => 'Secret123'], $as)
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => 'New@Example.com', 'current_password' => 'Secret123'], $as)
            ->assertOk()->assertJsonPath('user.email', 'new@example.com');
        // Role and other fields cannot be changed this way.
        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => 'new@example.com', 'role' => 'admin'], $as)->assertOk();
        $this->assertSame('staff', $this->staff->fresh()->role->value);
    }
}
