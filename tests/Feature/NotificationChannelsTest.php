<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PushSubscription;
use App\Models\Store;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\WorkflowAlert;
use App\Services\IssueService;
use App\Services\StockCountService;
use App\Services\StockTransferService;
use App\Support\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** In-app, e-mail and browser push delivery, user preferences, devices, live polling and the added workflow events. */
class NotificationChannelsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        config(['cms.push.public_key' => 'BPublicKeyForTests', 'cms.push.private_key' => 'privateKeyForTests', 'cms.mail_alerts' => true]);
    }

    private function device(User $u, string $endpoint = 'https://push.example.test/abc'): PushSubscription
    {
        return PushSubscription::create(['user_id' => $u->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint), 'public_key' => 'p', 'auth_token' => 'a']);
    }

    /** Replaces the real Web Push sender and records what would have been sent. */
    private function fakePush(): object
    {
        $fake = new class extends PushSender {
            public array $sent = [];

            public function send(Collection $subscriptions, array $payload): int
            {
                foreach ($subscriptions as $s) {
                    $this->sent[] = ['user_id' => $s->user_id, 'payload' => $payload];
                }

                return $subscriptions->count();
            }
        };
        $this->app->instance(PushSender::class, $fake);

        return $fake;
    }

    public function test_channels_follow_global_and_personal_settings(): void
    {
        $alert = new WorkflowAlert('S', 'B', url('/'));
        $this->assertSame(['database', 'mail'], $alert->via($this->pm));

        $this->device($this->pm);
        $this->assertSame(['database', 'mail', WebPushChannel::class], $alert->via($this->pm->fresh()));

        $this->pm->forceFill(['notify_email' => false, 'notify_push' => false])->save();
        $this->assertSame(['database'], $alert->via($this->pm->fresh()));

        config(['cms.push.public_key' => null, 'cms.mail_alerts' => false]);
        $this->assertSame(['database'], $alert->via($this->staff));
    }

    public function test_submitting_a_request_pushes_to_the_pm_device_with_a_link_that_marks_it_read(): void
    {
        $push = $this->fakePush();
        $this->device($this->pm);
        $req = $this->submittedRequest(5);

        $this->assertCount(1, $push->sent);
        $this->assertSame($this->pm->id, $push->sent[0]['user_id']);
        $this->assertStringContainsString($req->request_no, $push->sent[0]['payload']['title']);
        $n = $this->pm->notifications()->sole();
        $this->assertSame(route('notifications.open', $n->id), $push->sent[0]['payload']['url']);
    }

    public function test_user_turns_push_on_and_off_and_manages_devices(): void
    {
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth'], 'contentEncoding' => 'aes128gcm'];
        $this->actingAs($this->staff)->get(route('notifications.settings'))->assertOk()->assertSee('Turn on push for this device');
        $this->actingAs($this->staff)->postJson(route('push.subscribe'), $sub)->assertOk();
        $this->actingAs($this->staff)->postJson(route('push.subscribe'), $sub)->assertOk();   // same device again: no duplicate
        $this->assertSame(1, $this->staff->pushSubscriptions()->count());

        // Another person signs in on the same browser: the device moves to them.
        $this->actingAs($this->pm)->postJson(route('push.subscribe'), $sub)->assertOk();
        $this->assertSame(0, $this->staff->pushSubscriptions()->count());
        $device = $this->pm->pushSubscriptions()->sole();

        $this->actingAs($this->staff)->delete(route('push.unsubscribe', $device));       // not theirs: nothing happens
        $this->assertModelExists($device);
        $this->actingAs($this->pm)->deleteJson(route('push.unsubscribe'), ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertModelMissing($device);

        $this->actingAs($this->staff)->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertUnprocessable();
        config(['cms.push.public_key' => null]);
        $this->actingAs($this->staff)->postJson(route('push.subscribe'), $sub)->assertStatus(409);
    }

    public function test_preferences_test_alert_and_live_polling(): void
    {
        $this->actingAs($this->staff)->put(route('notifications.settings.update'), ['notify_email' => '0', 'notify_push' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse($this->staff->fresh()->notify_email);

        $since = now()->subSecond()->toIso8601String();
        $this->actingAs($this->staff)->post(route('notifications.test'))->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->getJson(route('notifications.poll', ['since' => $since]))
            ->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('fresh.0.subject', 'Test notification');
        $this->actingAs($this->staff)->getJson(route('notifications.poll'))->assertJsonCount(0, 'fresh');
    }

    public function test_added_request_events_notify_the_right_people(): void
    {
        Notification::fake();

        $req = $this->submittedRequest(10);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        Notification::assertSentTo($this->staff, WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'PM approved'));

        $other = $this->submittedRequest(3);
        $this->wf()->cancel($this->staff, $other);
        Notification::assertSentTo($this->pm, WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'Cancelled'));

        $this->receive($this->cement, 50);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $issue = app(IssueService::class)->issue($this->store, $req->fresh(), [$req->lines()->first()->id => 10], 'Site foreman');
        app(IssueService::class)->acknowledge($this->staff, $issue, 'Site foreman');
        Notification::assertSentTo($this->store, WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'Receipt confirmed'));
    }

    public function test_transfer_and_count_outcomes_notify_the_person_who_started_them(): void
    {
        Notification::fake();
        $site = Store::create(['code' => 'SITE', 'name' => 'Site store', 'type' => 'site', 'project_id' => $this->project->id]);
        $keeper = User::factory()->role(Role::Storekeeper)->create();
        $keeper->stores()->attach($site->id);
        $this->receive($this->cement, 20);

        $transfers = app(StockTransferService::class);
        $t = $transfers->dispatch($this->store, Store::DEFAULT_ID, $site->id, [['item_id' => $this->cement->id, 'qty' => 5]]);
        $transfers->receive($keeper, $t, [$t->lines()->first()->id => 5]);
        Notification::assertSentTo($this->store, WorkflowAlert::class, fn ($n) => str_starts_with($n->subject, 'Transfer received'));

        $counts = app(StockCountService::class);
        $count = $counts->create($this->store, Store::DEFAULT_ID);
        $counts->record($this->store, $count, $count->lines()->pluck('system_qty', 'id')->map(fn ($q) => ['counted_qty' => $q])->all());
        $counts->submit($this->store, $count);
        $counts->decide($this->overall, $count, 'approved');
        Notification::assertSentTo($this->store, WorkflowAlert::class, fn ($n) => $n->subject === "Stock count approved: {$count->count_no}");
    }
}
