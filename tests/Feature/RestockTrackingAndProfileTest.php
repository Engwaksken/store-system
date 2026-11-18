<?php

namespace Tests\Feature;

use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\IssueService;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Feedback round 2: requesters track restocking, issue statistics on dashboards, profile photo. */
class RestockTrackingAndProfileTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->buildFixtures();
    }

    private function tokenFor(User $u): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$u->createToken('test')->plainTextToken];
    }

    private function shortIssue()
    {
        $this->receive($this->cement, 6);
        $req = $this->approvedRequest(10);
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 6], 'Recipient');

        return [$req->fresh(), PurchaseRequisition::sole()];
    }

    public function test_requester_tracks_the_restock_without_stock_shortage_wording(): void
    {
        [$req, $pr] = $this->shortIssue();

        $this->assertTrue($this->staff->notifications()->where('data->subject', 'like', 'Restock requested%')->exists());
        $this->actingAs($this->staff)->get(route('requests.show', $req))->assertOk()
            ->assertSee('Restock progress')->assertSee($pr->pr_no)->assertSee('Waiting for the Overall Manager')
            ->assertDontSee('Not enough stock');
        $this->actingAs($this->store)->get(route('requests.show', $req))->assertOk()->assertSee('Not enough stock');

        $this->getJson("/api/v1/requests/{$req->id}", $this->tokenFor($this->staff))->assertOk()
            ->assertJsonPath('data.restock.0.pr_no', $pr->pr_no)->assertJsonPath('data.restock.0.status', 'submitted');

        app(PurchasingService::class)->decideRequisition($this->overall, $pr, 'approved', null);
        $this->assertTrue($this->staff->notifications()->where('data->subject', 'like', 'Restock for%approved')->exists());
        $this->getJson("/api/v1/requests/{$req->id}", $this->tokenFor($this->staff))->assertOk()
            ->assertJsonPath('data.restock.0.stage', 'Restock approved; procurement is getting quotes and ordering.');
    }

    public function test_dashboards_show_issue_statistics(): void
    {
        $this->shortIssue();
        $this->getJson('/api/v1/dashboard', $this->tokenFor($this->staff))->assertOk()
            ->assertJsonFragment(['key' => 'issued_vouchers_month', 'value' => 1])
            ->assertJsonFragment(['key' => 'issued_items_month', 'value' => 1])
            ->assertJsonFragment(['key' => 'issues_unacknowledged', 'value' => 1]);
        // Another project's staff member sees none of it.
        $this->getJson('/api/v1/dashboard', $this->tokenFor($this->outsider))->assertOk()->assertJsonFragment(['key' => 'issued_vouchers_month', 'value' => 0]);
        $this->actingAs($this->store)->get(route('dashboard'))->assertOk()->assertSee('Items issued this month');
    }

    public function test_user_uploads_and_removes_a_profile_photo(): void
    {
        Storage::fake('public');
        $h = $this->tokenFor($this->staff);
        $url = $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)], $h + ['Accept' => 'application/json'])
            ->assertOk()->json('user.avatar_url');
        $this->assertNotNull($url);
        $path = $this->staff->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);

        $this->post('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], $h + ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->deleteJson('/api/v1/me/avatar', [], $h)->assertOk()->assertJsonPath('user.avatar_url', null);
        Storage::disk('public')->assertMissing($path);
    }
}
