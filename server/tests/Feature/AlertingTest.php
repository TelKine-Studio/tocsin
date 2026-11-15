<?php

namespace Tests\Feature;

use App\Actions\PublishMessage;
use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\EscalationNotification;
use App\Push\PushPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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
        $message = Message::factory()->for($this->topic)->create(['requires_ack' => true, 'next_escalation_at' => now()->addMinutes(5), 'dispatched_at' => now()]);

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
        $this->assertSame('✓ Acknowledged by iPhone de Awa', $payload['aps']['alert']['body']);
        $this->assertSame('passive', $payload['aps']['interruption-level']);
        $this->assertArrayNotHasKey('sound', $payload['aps']);

        $this->postJson($message->acknowledgeUrl())->assertJson(['already' => true, 'by' => 'iPhone de Awa']);
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
