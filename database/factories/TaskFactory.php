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
            'assigned_to' => User::inRandomOrder()->value('id'),
            'created_by' => null,
        ];
    }
}
