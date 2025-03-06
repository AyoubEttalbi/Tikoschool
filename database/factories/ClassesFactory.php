<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ClassesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['2BAC SVT', '2BAC PC', 'BAC SVT']) . ' G' . $this->faker->unique()->numberBetween(1, 100),
            'level' => $this->faker->randomElement(['BAC', '2BAC']),
            'numStudents' => $this->faker->numberBetween(20, 30),
            'numTeachers' => $this->faker->numberBetween(1, 3),
        ];
    }
}