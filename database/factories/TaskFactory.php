<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::inRandomOrder()->value('id') ?? School::factory(),
            'title' => $this->faker->sentence(4, true),
            'description' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(['todo', 'todo', 'in_progress', 'done']),
            'priority' => $this->faker->randomElement(['low', 'normal', 'normal', 'high']),
            'due_date' => $this->faker->optional(0.7)->dateTimeBetween('-1 week', '+2 weeks')?->format('Y-m-d'),
            // Null by default: a random existing user made every un-assigned card
            // silently "belong" to whoever happened to be in the users table —
            // order-dependent flakes in any ownership-filtered test.
            'assigned_to' => null,
            'created_by' => null,
        ];
    }
}
