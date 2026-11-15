<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Models\Message;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'topic_id' => Topic::factory(),
            'title' => fake()->optional()->sentence(4),
            'body' => fake()->paragraph(),
            'priority' => Priority::Default,
            'tags' => null,
            'dispatched_at' => now(),
        ];
    }
}
