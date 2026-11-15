<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Topic;
use App\Models\TopicWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->topic = Topic::factory()->create(['name' => 'alerts']);
    }

    protected function hook(string $source = 'auto'): TopicWebhook
    {
        return $this->topic->webhooks()->create(['name' => 'Test', 'source' => $source, 'user_id' => $this->topic->owner_id]);
    }

    protected function latest(): Message
    {
        return $this->topic->messages()->latest('id')->first();
    }

    public function test_owners_create_and_delete_webhooks(): void
    {
        $this->actingAs($this->topic->owner)->post('/topics/alerts/webhooks', ['name' => 'Grafana', 'source' => 'grafana'])->assertRedirect();
        $webhook = TopicWebhook::sole();
        $this->assertSame(40, strlen($webhook->token));
        $this->actingAs($this->topic->owner)->get('/topics/alerts')->assertSee($webhook->url());

        $this->actingAs(User::factory()->create())->post('/topics/alerts/webhooks', ['name' => 'X', 'source' => 'auto'])->assertForbidden();

        $this->actingAs($this->topic->owner)->delete("/topics/alerts/webhooks/{$webhook->id}")->assertRedirect();
        $this->assertModelMissing($webhook);
    }

    public function test_unknown_tokens_are_rejected(): void
    {
        $this->postJson('/hooks/nope', ['message' => 'x'])->assertNotFound();
    }

    public function test_alertmanager_payloads(): void
    {
        $hook = $this->hook();

        $this->postJson($hook->url(), [
            'status' => 'firing',
            'commonLabels' => ['alertname' => 'HighCPU', 'severity' => 'critical'],
            'externalURL' => 'https://alertmanager.example.com',
            'alerts' => [
                ['labels' => ['alertname' => 'HighCPU', 'instance' => 'web-1'], 'annotations' => ['summary' => 'CPU above 95%']],
                ['labels' => ['alertname' => 'HighCPU', 'instance' => 'web-2'], 'annotations' => ['summary' => 'CPU above 95%']],
            ],
        ])->assertStatus(202);

        $message = $this->latest();
        $this->assertSame('🔥 HighCPU (2)', $message->title);
        $this->assertSame("[web-1] CPU above 95%\n[web-2] CPU above 95%", $message->body);
        $this->assertSame(5, $message->priority->value);
        $this->assertSame('https://alertmanager.example.com', $message->click_url);
        $this->assertSame(1, $hook->fresh()->calls);
    }

    public function test_github_events(): void
    {
        $hook = $this->hook();

        $this->withHeaders(['X-GitHub-Event' => 'workflow_run'])->postJson($hook->url(), [
            'repository' => ['full_name' => 'TelKine-Studio/tocsin'],
            'workflow_run' => ['name' => 'Server', 'conclusion' => 'failure', 'head_branch' => 'main', 'html_url' => 'https://github.com/TelKine-Studio/tocsin/actions/runs/1'],
        ])->assertStatus(202);

        $this->assertSame('TelKine-Studio/tocsin · Server', $this->latest()->title);
        $this->assertSame(4, $this->latest()->priority->value);
    }

    public function test_uptime_kuma_down_is_urgent(): void
    {
        $this->postJson($this->hook()->url(), [
            'heartbeat' => ['status' => 0, 'msg' => 'Timeout'],
            'monitor' => ['name' => 'Website', 'url' => 'https://example.com'],
            'msg' => '[Website] [🔴 Down] Timeout',
        ])->assertStatus(202);

        $this->assertSame('🔴 Website', $this->latest()->title);
        $this->assertSame(5, $this->latest()->priority->value);
    }

    public function test_slack_and_discord_compatible_payloads(): void
    {
        $hook = $this->hook();

        $this->postJson($hook->url(), ['text' => "*Deployment succeeded*\nApplication <https://app.example.com|my-app> is live."])->assertStatus(202);
        $this->assertSame('Deployment succeeded', $this->latest()->title);
        $this->assertSame('Application my-app is live.', $this->latest()->body);

        $this->postJson($hook->url(), ['embeds' => [['title' => 'Backup', 'description' => 'Done in 42s', 'url' => 'https://example.com']]])->assertStatus(202);
        $this->assertSame('Backup', $this->latest()->title);
        $this->assertSame('Done in 42s', $this->latest()->body);
    }

    public function test_generic_json_and_text(): void
    {
        $hook = $this->hook('generic');

        $this->postJson($hook->url(), ['title' => 'Hello', 'message' => 'World', 'priority' => 'high'])->assertStatus(202);
        $this->assertSame('World', $this->latest()->body);
        $this->assertSame(4, $this->latest()->priority->value);

        $this->call('POST', $hook->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Plain text alert')->assertStatus(202);
        $this->assertSame('Plain text alert', $this->latest()->body);
    }
}
