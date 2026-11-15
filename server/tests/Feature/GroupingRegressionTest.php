<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_different_ntfy_messages_are_not_grouped(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Topic::factory()->for($user, 'owner')->create(['name' => 'payer']);
        $token = $user->createToken('cli')->plainTextToken;

        foreach (range(1, 4) as $i) {
            $this->withToken($token)->call('POST', '/payer', [], [], [], [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'HTTP_TITLE' => "PAY-20261115-000{$i} - 100000 F au 0555862371 (parti)",
                'HTTP_PRIORITY' => 'high',
                'HTTP_TAGS' => 'rotating_light,money_with_wings',
            ], "Un paiement fournisseur a ete lance.\n\nReference : PAY-20261115-000{$i}")->assertSuccessful();
        }

        $this->assertSame(4, Message::count(), Message::pluck('collapse_key', 'title')->toJson());
    }

    public function test_grouped_messages_keep_each_send(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user, 'owner')->create(['name' => 'cpu']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->travelTo(now()->setTime(16, 49, 0));
        $this->withToken($token)->postJson('/api/v1/topics/cpu/messages', ['message' => 'CPU at 91%', 'collapse' => 'cpu-web-1'])->assertCreated();
        $this->travel(5)->seconds();
        $this->withToken($token)->postJson('/api/v1/topics/cpu/messages', ['message' => 'CPU at 97%', 'collapse' => 'cpu-web-1'])->assertCreated();
        $this->travel(5)->seconds();
        $this->withToken($token)->postJson('/api/v1/topics/cpu/messages', ['message' => 'CPU at 97%', 'collapse' => 'cpu-web-1'])->assertCreated();

        $message = Message::sole();
        $this->assertSame(3, $message->occurrences);
        $this->assertSame(['CPU at 91%', 'CPU at 97%', 'CPU at 97%'], array_column($message->repeatLog(), 'body'));

        $this->withToken($token)->getJson('/api/v1/topics/cpu/messages')
            ->assertJsonPath('data.0.occurrences', 3)
            ->assertJsonPath('data.0.repeats.0.message', 'CPU at 91%')
            ->assertJsonPath('data.0.repeats.2.time', now()->getTimestamp());

        $this->actingAs($user)->get('/topics/cpu')
            ->assertSee(__('Received :count times', ['count' => 3]))
            ->assertSee('CPU at 91%')
            ->assertSee(__('same content'));
    }
}
