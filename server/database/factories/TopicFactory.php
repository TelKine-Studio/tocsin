<?php

namespace Database\Factories;

use App\Enums\TopicVisibility;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => Str::slug(fake()->unique()->words(2, true)),
            'display_name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'visibility' => TopicVisibility::Private,
            'anonymous_publish' => false,
            'owner_id' => User::factory(),
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => TopicVisibility::Public]);
    }

    public function anonymousPublish(): static
    {
        return $this->public()->state(fn () => ['anonymous_publish' => true]);
    }
}
