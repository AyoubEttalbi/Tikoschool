<?php

namespace Database\Factories;

use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassesFactory extends Factory
{
    public function definition(): array
    {
        // Get a random level ID
        $levelIds = Level::pluck('id')->toArray();
        $levelId = !empty($levelIds) ? $this->faker->randomElement($levelIds) : 1;
        
        return [
            'name' => $this->faker->randomElement(['2BAC SVT', '2BAC PC', 'BAC SVT']) . ' G' . $this->faker->unique()->numberBetween(1, 100),
            'level_id' => $levelId,
            'number_of_students' => $this->faker->numberBetween(20, 30),
            'number_of_teachers' => $this->faker->numberBetween(1, 3),
        ];
    }
}