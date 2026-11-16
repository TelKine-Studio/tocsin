<?php

namespace Tests\Feature;

use App\Actions\AcknowledgeMessage;
use App\Actions\PublishMessage;
use App\Jobs\SendMessagePush;
use App\Models\Device;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\EscalationNotification;
use App\Push\PushPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlertingTest extends TestCase
{
    use RefreshDatabase;

    protected Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->topic = Topic::factory()->anonymousPublish()->create(['name' => 'alerts']);
    }

    protected function publish(string $body, array $headers = []): array
    {
        return $this->call('POST', '/alerts', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $body)
            ->assertOk()
            ->json();
    }

    // Grouping ---------------------------------------------------------------

    public function test_identical_messages_are_grouped_without_notifying_again(): void
    {
        $first = $this->publish('Disk almost full');
        $second = $this->publish('Disk almost full');
        $third = $this->publish('Disk almost full');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(3, $third['occurrences']);
        $this->assertSame(1, $this->topic->messages()->count());
        $this->assertSame(3, Message::sole()->occurrences);
        Queue::assertPushed(SendMessagePush::class, 1);

        // Different content: a new message.
        $this->publish('Disk full');
        $this->assertSame(2, $this->topic->messages()->count());
    }

    public function test_grouping_stops_after_the_window(): void
    {
        $this->publish('Backup failed');
        $this->travel(11)->minutes();
        $this->publish('Backup failed');

        $this->assertSame(2, $this->topic->messages()->count());
    }

    public function test_grouping_can_be_disabled(): void
    {
        $this->topic->update(['group_window' => 0]);

        $this->publish('Doorbell', ['X-Collapse' => 'door']);
        $this->publish('Doorbell', ['X-Collapse' => 'door']);

        $this->assertSame(2, $this->topic->messages()->count());
        $this->assertSame('key:door', Message::first()->collapse_key);
    }

    public function test_collapse_keys_keep_the_latest_content_and_notify_when_the_priority_rises(): void
    {
        $this->publish('CPU at 91%', ['X-Collapse' => 'cpu-web1', 'X-Priority' => '3']);
        $this->publish('CPU at 93%', ['X-Collapse' => 'cpu-web1', 'X-Priority' => '3']);

        $message = Message::sole();
        $this->assertSame('CPU at 93%', $message->body);
        $this->assertSame(2, $message->occurrences);
        Queue::assertPushed(SendMessagePush::class, 1);

        $this->publish('CPU at 99%', ['X-Collapse' => 'cpu-web1', 'X-Priority' => '5']);
        $this->assertSame(5, $message->fresh()->priority->value);
        Queue::assertPushed(SendMessagePush::class, 2);

        // A lower priority never lowers the message.
        $this->publish('CPU at 95%', ['X-Collapse' => 'cpu-web1', 'X-Priority' => '2']);
        $this->assertSame(5, $message->fresh()->priority->value);
        Queue::assertPushed(SendMessagePush::class, 2);
    }

    public function test_grouped_notifications_show_the_counter(): void
    {
        $this->publish('Disk almost full');
        $this->publish('Disk almost full');

        $payload = PushPayload::forMessage(Message::sole()->load('topic'), 'https://notify.example.com');
        $this->assertSame('×2 · Disk almost full', $payload['aps']['alert']['body']);
    }

    public function test_messages_with_files_or_delays_are_not_grouped(): void
    {
        $this->publish('Report', ['X-Delay' => '1h']);
        $this->publish('Report', ['X-Delay' => '1h']);

        $this->assertSame(2, $this->topic->messages()->count());
    }

    // Acknowledgement --------------------------------------------------------

    public function test_urgent_messages_require_an_acknowledgement(): void
    {
        $this->topic->update(['ack_priority' => 5]);

        $normal = $this->publish('Info');
        $this->assertArrayNotHasKey('ack', $normal);

        $urgent = $this->publish('Server down', ['X-Priority' => 'urgent']);
        $this->assertSame(['acknowledged' => false], $urgent['ack']);
        $this->assertSame('Acknowledge', $urgent['actions'][0]['label']);
        $this->assertTrue($urgent['actions'][0]['ack']);

        // X-Ack forces it either way.
        $this->assertArrayHasKey('ack', $this->publish('Deploy?', ['X-Ack' => 'yes']));
        $this->assertArrayNotHasKey('ack', $this->publish('Down again', ['X-Priority' => '5', 'X-Ack' => 'no']));
    }

    public function test_the_signed_button_acknowledges_the_message(): void
    {
        $message = Message::factory()->for($this->topic)->create(['body' => 'Server down', 'requires_ack' => true, 'next_escalation_at' => now()->addMinutes(5), 'dispatched_at' => now()]);

        $this->postJson('/v1/messages/'.$message->id.'/ack')->assertForbidden();

        $this->postJson($message->acknowledgeUrl(), [], ['X-Name' => 'iPhone de Awa'])
            ->assertOk()
            ->assertJson(['acknowledged' => true, 'already' => false, 'by' => 'iPhone de Awa']);

        $message->refresh();
        $this->assertTrue($message->isAcknowledged());
        $this->assertNull($message->next_escalation_at);
        $this->assertSame([], $message->actionsWithAcknowledgement());

        // Everyone's notification is replaced by "Acknowledged by …".
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->notice === SendMessagePush::ACKNOWLEDGED);
        $payload = PushPayload::forMessage($message->load('topic'), 'https://notify.example.com', SendMessagePush::ACKNOWLEDGED);
        $this->assertSame("✓ Acknowledged by iPhone de Awa\nServer down", $payload['aps']['alert']['body']);
        $this->assertSame('passive', $payload['aps']['interruption-level']);
        $this->assertArrayNotHasKey('sound', $payload['aps']);

        $this->postJson($message->acknowledgeUrl())->assertJson(['already' => true, 'by' => 'iPhone de Awa']);
    }

    public function test_the_acknowledgement_notice_recalls_the_message(): void
    {
        $this->topic->update(['display_name' => 'Alerts']);
        $message = Message::factory()->for($this->topic)->create([
            'title' => 'Payment failed',
            'body' => "Order #4521\n\nCard declined by the bank ".str_repeat('x', 300),
            'requires_ack' => true,
            'dispatched_at' => now(),
        ]);
        $this->postJson($message->acknowledgeUrl(), [], ['X-Name' => 'Awa'])->assertOk();

        $payload = PushPayload::forMessage($message->fresh()->load('topic'), 'https://notify.example.com', SendMessagePush::ACKNOWLEDGED);
        $alert = $payload['aps']['alert'];

        // Which message, in which topic, and who handled it.
        $this->assertSame('Payment failed', $alert['title']);
        $this->assertSame('Alerts', $alert['subtitle']);
        [$who, $excerpt] = explode("\n", $alert['body']);
        $this->assertSame('✓ Acknowledged by Awa', $who);
        $this->assertStringStartsWith('Order #4521 Card declined by the bank xxx', $excerpt);
        $this->assertLessThanOrEqual(143, mb_strlen($excerpt));

        // Tapping it opens the message: the apps get its id and know the
        // text is a notice, not the message.
        $this->assertSame($message->id, $payload['tocsin']['id']);
        $this->assertSame('alerts', $payload['tocsin']['topic']);
        $this->assertSame(SendMessagePush::ACKNOWLEDGED, $payload['tocsin']['notice']);
        $this->assertArrayNotHasKey('actions', $payload['tocsin']);
        $this->assertSame(SendMessagePush::ACKNOWLEDGED, PushPayload::forFcm($payload)['notice']);

        // Regular notifications carry no notice.
        $this->assertArrayNotHasKey('notice', PushPayload::forMessage($message, 'https://notify.example.com')['tocsin']);
    }

    public function test_whoever_acknowledges_is_not_notified(): void
    {
        $awa = User::factory()->create(['name' => 'Awa']);
        $bob = User::factory()->create();
        $awaPhone = Device::create(['user_id' => $awa->id, 'token' => str_repeat('a1', 32), 'platform' => 'ios', 'environment' => 'sandbox']);
        $awaTablet = Device::create(['user_id' => $awa->id, 'token' => str_repeat('a2', 32), 'platform' => 'android', 'environment' => 'production']);
        $bobPhone = Device::create(['user_id' => $bob->id, 'token' => str_repeat('b1', 32), 'platform' => 'ios', 'environment' => 'sandbox']);
        $guestPhone = Device::create(['token' => str_repeat('c1', 32), 'platform' => 'ios', 'environment' => 'sandbox']);
        $this->topic->devices()->attach([$awaPhone->id, $awaTablet->id, $bobPhone->id, $guestPhone->id]);

        // Signed in: none of the devices of the account.
        $message = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'dispatched_at' => now()]);
        $this->withToken($awa->createToken('app')->plainTextToken)
            ->postJson($message->acknowledgeUrl(), [], ['X-Device-Token' => str_repeat('a1', 32)])
            ->assertOk();
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->message->is($message)
            && $job->notice === SendMessagePush::ACKNOWLEDGED
            && collect($job->except)->sort()->values()->all() === [$awaPhone->id, $awaTablet->id]);

        // Anonymous: the device that acknowledged, known by its push token.
        $this->app['auth']->forgetGuards();
        $other = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'dispatched_at' => now()]);
        $this->withHeaders(['Authorization' => ''])
            ->postJson($other->acknowledgeUrl(), [], ['X-Name' => 'iPhone', 'X-Device-Token' => str_repeat('c1', 32)])
            ->assertOk();
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->message->is($other) && $job->except === [$guestPhone->id]);

        // From the web interface: the devices of the signed-in user.
        $this->topic->update(['user_id' => $bob->id]);
        $third = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'dispatched_at' => now()]);
        $this->actingAs($bob)->post("/topics/alerts/messages/{$third->id}/acknowledge")->assertSessionHas('status');
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->message->is($third) && $job->except === [$bobPhone->id]);

        // A "resolved" alert closes it for everyone: nobody is left out.
        $publish = app(PublishMessage::class);
        $publish->handle($this->topic, ['body' => 'Down', 'collapse' => 'db', 'ack' => true]);
        $publish->handle($this->topic, ['body' => 'Back up', 'resolves' => 'db']);
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->notice === SendMessagePush::ACKNOWLEDGED && $job->message->collapse_key === 'key:db' && $job->except === []);
    }

    // Acknowledgement groups -------------------------------------------------

    /** One event published in the topics of two accounts, linked by X-Ack-Group. */
    protected function publishEvent(string $key, array $extra = []): array
    {
        $awa = User::factory()->create(['name' => 'Awa']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $awaTopic = Topic::factory()->for($awa, 'owner')->create(['name' => 'awa-admin']);
        $bobTopic = Topic::factory()->for($bob, 'owner')->create(['name' => 'bob-admin']);
        $awaPhone = Device::create(['user_id' => $awa->id, 'token' => str_repeat('a1', 32), 'platform' => 'ios', 'environment' => 'sandbox']);
        $bobPhone = Device::create(['user_id' => $bob->id, 'token' => str_repeat('b1', 32), 'platform' => 'ios', 'environment' => 'sandbox']);
        $awaTopic->devices()->attach($awaPhone);
        $bobTopic->devices()->attach($bobPhone);

        $publish = app(PublishMessage::class);
        $mine = $publish->handle($awaTopic, ['body' => 'Withdrawal #4521 to validate', 'ack' => true, 'ack_group' => $key, ...$extra]);
        $theirs = $publish->handle($bobTopic, ['body' => 'Withdrawal #4521 to validate', 'ack' => true, 'ack_group' => $key, ...$extra]);

        return compact('awa', 'bob', 'awaTopic', 'bobTopic', 'awaPhone', 'bobPhone', 'mine', 'theirs');
    }

    public function test_acknowledging_one_message_of_a_group_acknowledges_the_others(): void
    {
        $event = $this->publishEvent('marecharge:withdrawal:4521:9f2c7a1e');
        $other = app(PublishMessage::class)->handle($event['bobTopic'], ['body' => 'Another event', 'ack' => true, 'ack_group' => 'another-event-key-123']);
        $alone = app(PublishMessage::class)->handle($event['bobTopic'], ['body' => 'No group', 'ack' => true]);

        // The key itself is never stored.
        $this->assertSame(hash('sha256', 'marecharge:withdrawal:4521:9f2c7a1e'), $event['mine']->fresh()->ack_group);

        $this->withToken($event['awa']->createToken('app')->plainTextToken)
            ->postJson($event['mine']->acknowledgeUrl())
            ->assertOk();

        // Bob's message, in Bob's topic, is acknowledged by Awa.
        $theirs = $event['theirs']->fresh();
        $this->assertTrue($theirs->isAcknowledged());
        $this->assertSame($event['awa']->id, $theirs->acknowledged_by);
        $this->assertNull($theirs->next_escalation_at);
        $this->assertSame('Acknowledged by Awa', $theirs->acknowledgementLabel());

        // Other events are left alone.
        $this->assertFalse($other->fresh()->isAcknowledged());
        $this->assertFalse($alone->fresh()->isAcknowledged());

        // Bob is told on his own alert (Awa has no device on his topic),
        // Awa is not: she did it.
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->message->is($theirs)
            && $job->notice === SendMessagePush::ACKNOWLEDGED
            && $job->except === []);
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->message->is($event['mine'])
            && $job->notice === SendMessagePush::ACKNOWLEDGED
            && $job->except === [$event['awaPhone']->id]);
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->notice === SendMessagePush::ACKNOWLEDGED, 2);

        // Acknowledging Bob's afterwards: already done.
        $this->postJson($theirs->acknowledgeUrl())->assertJson(['already' => true, 'by' => 'Awa']);
    }

    public function test_a_late_message_of_an_acknowledged_group_arrives_acknowledged(): void
    {
        $event = $this->publishEvent('marecharge:withdrawal:4522:0b7d3e5f');
        app(AcknowledgeMessage::class)->handle($event['mine'], $event['awa']);

        $carol = Topic::factory()->create(['name' => 'carol-admin', 'escalation' => [['after' => 5, 'users' => []]]]);
        $late = app(PublishMessage::class)->handle($carol, ['body' => 'Withdrawal #4522 to validate', 'ack' => true, 'ack_group' => 'marecharge:withdrawal:4522:0b7d3e5f']);

        $this->assertTrue($late->fresh()->isAcknowledged());
        $this->assertSame($event['awa']->id, $late->fresh()->acknowledged_by);
        $this->assertNull($late->fresh()->next_escalation_at);
        $this->assertSame([], $late->fresh()->actionsWithAcknowledgement());
    }

    public function test_acknowledgement_groups_are_limited_in_time(): void
    {
        $event = $this->publishEvent('marecharge:withdrawal:4523:5c1a9b2d');
        $event['theirs']->forceFill(['created_at' => now()->subDays(Message::ACK_GROUP_WINDOW_DAYS + 1)])->save();

        app(AcknowledgeMessage::class)->handle($event['mine'], $event['awa']);

        $this->assertFalse($event['theirs']->fresh()->isAcknowledged());
    }

    public function test_acknowledgement_group_keys_must_not_be_guessable(): void
    {
        $topic = Topic::factory()->create();

        $this->expectException(ValidationException::class);
        app(PublishMessage::class)->handle($topic, ['body' => 'Hi', 'ack_group' => 'short']);
    }

    public function test_the_ack_group_header_is_read_by_both_apis(): void
    {
        $this->publish('Down', ['X-Ack' => 'yes', 'X-Ack-Group' => 'header-group-key-0001']);
        $this->assertSame(hash('sha256', 'header-group-key-0001'), Message::latest('id')->first()->ack_group);

        $this->postJson('/', ['topic' => 'alerts', 'message' => 'Down again', 'ack' => true, 'ack_group' => 'json-group-key-00002'])->assertOk();
        $this->assertSame(hash('sha256', 'json-group-key-00002'), Message::latest('id')->first()->ack_group);

        $this->postJson('/api/v1/topics/alerts/messages', ['message' => 'Down 3', 'ack' => true, 'ack_group' => 'api-group-key-000003'])->assertCreated();
        $this->assertSame(hash('sha256', 'api-group-key-000003'), Message::latest('id')->first()->ack_group);
    }

    public function test_a_token_identifies_who_acknowledged(): void
    {
        $user = User::factory()->create(['name' => 'Awa']);
        $token = $user->createToken('app')->plainTextToken;
        $message = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'dispatched_at' => now()]);

        $this->withToken($token)->postJson($message->acknowledgeUrl())->assertJson(['by' => 'Awa']);
        $this->assertSame($user->id, $message->fresh()->acknowledged_by);
    }

    public function test_acknowledging_from_the_web_interface(): void
    {
        $message = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'dispatched_at' => now()]);
        $owner = $this->topic->owner;

        $this->actingAs($owner)->get('/topics/alerts')->assertSee(__('Waiting for acknowledgement'));
        $this->actingAs($owner)->get("/topics/alerts/messages/{$message->id}")->assertOk()->assertSee(__('Acknowledge'));

        $this->actingAs($owner)->post("/topics/alerts/messages/{$message->id}/acknowledge")->assertSessionHas('status');
        $this->assertSame($owner->id, $message->fresh()->acknowledged_by);

        $this->actingAs($owner)->get('/topics/alerts')->assertSee(__('Acknowledged by :name', ['name' => $owner->name]));
    }

    // Escalation -------------------------------------------------------------

    public function test_unacknowledged_messages_are_escalated(): void
    {
        Notification::fake();
        $oncall = User::factory()->create();
        $outsider = User::factory()->create();
        $this->topic->update(['ack_priority' => 4, 'escalation' => [
            ['after' => 5, 'users' => []],
            ['after' => 15, 'users' => [$oncall->id, $outsider->id]],
        ]]);
        $this->topic->update(['visibility' => 'private']);
        $this->topic->members()->attach($oncall, ['can_read' => true, 'can_write' => false]);

        $message = Message::factory()->for($this->topic)->create(['priority' => 5, 'requires_ack' => true]);
        app(PublishMessage::class)->deliver($message->load('topic'));
        $this->assertEquals(now()->addMinutes(5)->startOfSecond(), $message->fresh()->next_escalation_at->startOfSecond());

        // Too early: nothing happens.
        $this->artisan('tocsin:escalate')->assertSuccessful();
        $this->assertSame(0, $message->fresh()->escalation_level);

        $this->travel(5)->minutes();
        $this->artisan('tocsin:escalate')->assertSuccessful();
        $this->assertSame(1, $message->fresh()->escalation_level);
        Queue::assertPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->notice === SendMessagePush::REMINDER);
        Notification::assertNothingSent();

        $this->travel(10)->minutes();
        $this->artisan('tocsin:escalate')->assertSuccessful();
        $message->refresh();
        $this->assertSame(2, $message->escalation_level);
        $this->assertNull($message->next_escalation_at);
        Notification::assertSentTo($oncall, EscalationNotification::class);
        // Users who cannot read the topic are never emailed.
        Notification::assertNotSentTo($outsider, EscalationNotification::class);

        $payload = PushPayload::forMessage($message->load('topic'), 'https://notify.example.com', SendMessagePush::REMINDER);
        $this->assertStringStartsWith('Reminder: ', $payload['aps']['alert']['title']);
    }

    public function test_acknowledged_messages_are_not_escalated(): void
    {
        $this->topic->update(['ack_priority' => 5, 'escalation' => [['after' => 5, 'users' => []]]]);
        $id = $this->publish('Down', ['X-Priority' => '5'])['id'];
        $message = Message::find($id);

        $this->postJson($message->acknowledgeUrl())->assertOk();
        $this->travel(6)->minutes();
        $this->artisan('tocsin:escalate')->assertSuccessful();

        $this->assertSame(0, $message->fresh()->escalation_level);
        Queue::assertNotPushed(SendMessagePush::class, fn (SendMessagePush $job) => $job->notice === SendMessagePush::REMINDER);
    }

    public function test_resolved_alerts_close_the_firing_alert_even_without_grouping(): void
    {
        $this->topic->update(['ack_priority' => 4, 'group_window' => 0]);
        $hook = $this->topic->webhooks()->create(['name' => 'Kuma', 'source' => 'uptime_kuma', 'user_id' => $this->topic->owner_id]);

        $this->postJson($hook->url(), ['monitor' => ['name' => 'API'], 'heartbeat' => ['status' => 0], 'msg' => 'timeout'])->assertStatus(202);
        $down = Message::sole();
        $this->assertTrue($down->awaitsAcknowledgement());

        $this->postJson($hook->url(), ['monitor' => ['name' => 'API'], 'heartbeat' => ['status' => 1], 'msg' => 'OK'])->assertStatus(202);
        $this->assertTrue($down->fresh()->isAcknowledged());
    }

    public function test_resolved_alerts_close_the_firing_alert(): void
    {
        $this->topic->update(['ack_priority' => 4]);
        $hook = $this->topic->webhooks()->create(['name' => 'AM', 'source' => 'auto', 'user_id' => $this->topic->owner_id]);
        $alert = fn (string $status) => [
            'status' => $status,
            'groupKey' => '{}:{alertname="HighCPU"}',
            'commonLabels' => ['alertname' => 'HighCPU', 'severity' => 'critical'],
            'alerts' => [['labels' => ['alertname' => 'HighCPU'], 'annotations' => ['summary' => 'CPU above 95%']]],
        ];

        $this->postJson($hook->url(), $alert('firing'))->assertStatus(202);
        $this->postJson($hook->url(), $alert('firing'))->assertStatus(202);

        $firing = Message::sole();
        $this->assertSame(2, $firing->occurrences);
        $this->assertTrue($firing->awaitsAcknowledgement());

        $this->postJson($hook->url(), $alert('resolved'))->assertStatus(202);

        $this->assertSame(2, $this->topic->messages()->count());
        $this->assertTrue($firing->fresh()->isAcknowledged());
        $this->assertSame(__('Resolved automatically'), $firing->fresh()->acknowledgementLabel());
    }

    // Settings ---------------------------------------------------------------

    public function test_alert_settings_are_saved_from_the_topic_form(): void
    {
        $owner = $this->topic->owner;
        $member = User::factory()->create();
        $this->topic->members()->attach($member, ['can_read' => true, 'can_write' => false]);

        $this->actingAs($owner)->get('/topics/alerts/edit')->assertOk()
            ->assertViewHas('escalationUsers', fn ($users) => $users->pluck('id')->sort()->values()->all() === collect([$owner->id, $member->id])->sort()->values()->all());

        $this->actingAs($owner)->put('/topics/alerts', [
            'visibility' => 'public',
            'group_window' => 30,
            'ack_priority' => 4,
            'escalation' => [
                ['after' => 30, 'users' => [$member->id]],
                ['after' => 10],
            ],
        ])->assertSessionHasNoErrors();

        $topic = $this->topic->fresh();
        $this->assertSame(30, $topic->group_window);
        $this->assertSame(4, $topic->ack_priority);
        $this->assertSame([['after' => 10, 'users' => []], ['after' => 30, 'users' => [$member->id]]], $topic->escalationSteps());

        // No acknowledgement: no reminders.
        $this->actingAs($owner)->put('/topics/alerts', ['visibility' => 'public', 'group_window' => 0, 'ack_priority' => '', 'escalation' => [['after' => 5]]])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->topic->fresh()->escalation);

        $this->actingAs($owner)->put('/topics/alerts', ['visibility' => 'public', 'group_window' => 7])->assertSessionHasErrors('group_window');
    }
}
