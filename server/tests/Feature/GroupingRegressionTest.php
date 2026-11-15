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
}
