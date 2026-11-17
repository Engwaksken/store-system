<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\User;
use App\Notifications\AccountCredentials;
use App\Notifications\WorkflowAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Feedback (web + mobile API) and the mobile Users API. */
class FeedbackTest extends TestCase
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

    private function feedbackFrom(User $u, array $extra = []): Feedback
    {
        return Feedback::create($extra + ['user_id' => $u->id, 'category' => 'bug', 'subject' => 'Slow page', 'message' => 'The stock page is slow.']);
    }

    // ---------------------------------------------------------------- web

    public function test_any_user_sends_feedback_on_the_web_and_admins_are_alerted(): void
    {
        Notification::fake();
        $this->actingAs($this->staff)->get(route('feedback.create'))->assertOk()->assertSee('Send feedback');
        $this->actingAs($this->staff)->post(route('feedback.store'), [
            'category' => 'suggestion', 'subject' => 'Dark mode', 'message' => 'Please add a dark theme.', 'rating' => 4,
        ])->assertRedirect();

        $f = Feedback::sole();
        $this->assertSame([$this->staff->id, 'web', 'new', 4], [$f->user_id, $f->source, $f->status, $f->rating]);
        Notification::assertSentTo($this->admin, WorkflowAlert::class);
        Notification::assertNotSentTo($this->overall, WorkflowAlert::class);
    }

    public function test_users_only_see_their_own_feedback_and_admins_see_all(): void
    {
        $mine = $this->feedbackFrom($this->staff, ['subject' => 'Mine']);
        $theirs = $this->feedbackFrom($this->store, ['subject' => 'Theirs']);

        $this->actingAs($this->staff)->get(route('feedback.index'))->assertOk()->assertSee('Mine')->assertDontSee('Theirs');
        $this->actingAs($this->staff)->get(route('feedback.show', $theirs))->assertForbidden();
        $this->actingAs($this->staff)->get(route('feedback.show', $mine))->assertOk();
        $this->actingAs($this->admin)->get(route('feedback.index'))->assertOk()->assertSee('Mine')->assertSee('Theirs');
    }

    public function test_admin_reply_notifies_the_sender_and_others_cannot_reply(): void
    {
        Notification::fake();
        $f = $this->feedbackFrom($this->staff);

        $this->actingAs($this->staff)->put(route('feedback.respond', $f), ['status' => 'resolved', 'response' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->put(route('feedback.respond', $f), ['status' => 'resolved', 'response' => 'Fixed in today\'s update.'])->assertRedirect();

        $f->refresh();
        $this->assertSame(['resolved', 'Fixed in today\'s update.', $this->admin->id], [$f->status, $f->response, $f->responded_by]);
        Notification::assertSentTo($this->staff, WorkflowAlert::class, fn ($n) => str_contains($n->subject, 'Reply to your feedback'));
    }

    // ---------------------------------------------------------------- mobile API: feedback

    public function test_mobile_feedback_is_sent_listed_and_answered(): void
    {
        Notification::fake();
        $id = $this->postJson('/api/v1/feedback', [
            'category' => 'bug', 'subject' => 'Crash on approve', 'message' => 'The app closes when I approve.', 'app_version' => '1.0.0', 'device' => 'Pixel 7',
        ], $this->tokenFor($this->staff))->assertCreated()
            ->assertJsonPath('data.source', 'mobile')->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.device', null) // device details are for admins only
            ->json('data.id');

        $this->getJson('/api/v1/feedback', $this->tokenFor($this->store))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/feedback/{$id}", $this->tokenFor($this->store))->assertForbidden();
        $this->postJson("/api/v1/feedback/{$id}/respond", ['status' => 'closed'], $this->tokenFor($this->staff))->assertForbidden();

        $this->getJson('/api/v1/feedback', $this->tokenFor($this->admin))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.device', 'Pixel 7')->assertJsonPath('data.0.can_respond', true);
        $this->postJson("/api/v1/feedback/{$id}/respond", ['status' => 'in_review', 'response' => 'Looking into it.'], $this->tokenFor($this->admin))
            ->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.response', 'Looking into it.');
        Notification::assertSentTo($this->staff, WorkflowAlert::class);

        $this->getJson('/api/v1/feedback/options', $this->tokenFor($this->staff))->assertOk()->assertJsonPath('categories.0.value', 'bug');
    }

    public function test_me_exposes_admin_permissions_and_branding_has_subtitle(): void
    {
        $this->getJson('/api/v1/me', $this->tokenFor($this->admin))->assertOk()
            ->assertJsonPath('user.can.manage_users', true)->assertJsonPath('user.can.manage_feedback', true);
        $this->getJson('/api/v1/me', $this->tokenFor($this->staff))->assertOk()
            ->assertJsonPath('user.can.manage_users', false)->assertJsonPath('user.can.manage_feedback', false);
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('app_subtitle', 'Store & Projects')
            ->assertJsonPath('theme.primary', '#12243E')->assertJsonPath('theme.action', '#0F766E');
        \App\Models\AppSetting::put('theme_primary', '#7A1F2B');
        $this->getJson('/api/v1/branding')->assertJsonPath('theme.primary', '#7A1F2B');
    }

    // ---------------------------------------------------------------- mobile API: users

    public function test_only_admins_use_the_users_api(): void
    {
        $this->getJson('/api/v1/users', $this->tokenFor($this->overall))->assertForbidden();
        $this->getJson('/api/v1/users?status=active&q='.urlencode($this->staff->email), $this->tokenFor($this->admin))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->staff->id);
        $this->getJson('/api/v1/users/options', $this->tokenFor($this->admin))->assertOk()
            ->assertJsonPath('roles.0.value', 'admin')->assertJsonPath('projects.0.code', 'P-1');
    }

    public function test_admin_creates_and_edits_a_user_from_the_app(): void
    {
        Notification::fake();
        $res = $this->postJson('/api/v1/users', [
            'name' => 'Brian Okello', 'email' => 'brian@example.com', 'role' => 'staff', 'is_active' => true, 'project_ids' => [$this->project->id],
        ], $this->tokenFor($this->admin))->assertCreated()
            ->assertJsonPath('data.role', 'staff')->assertJsonPath('data.projects.0.code', 'P-1')
            ->assertJsonPath('credentials_emailed', true)->assertJsonPath('temporary_password', null);
        $id = $res->json('data.id');
        Notification::assertSentTo(User::find($id), AccountCredentials::class);

        // Staff needs a project; same rule as the web form.
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'staff', 'is_active' => true], $this->tokenFor($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('project_ids');

        $this->putJson("/api/v1/users/{$id}", [
            'name' => 'Brian O.', 'email' => 'brian@example.com', 'role' => 'storekeeper', 'is_active' => false, 'project_ids' => [],
        ], $this->tokenFor($this->admin))->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.role', 'storekeeper');

        // An admin cannot lock themselves out.
        $this->putJson("/api/v1/users/{$this->admin->id}", [
            'name' => $this->admin->name, 'email' => $this->admin->email, 'role' => 'admin', 'is_active' => false,
        ], $this->tokenFor($this->admin))->assertUnprocessable()->assertJsonValidationErrors('role');
    }
}
