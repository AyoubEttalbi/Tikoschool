<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassesFactory extends Factory
{
    public function definition(): array
    {
        // Create the parents when none exist rather than falling back to a hardcoded id
        // of 1, which violates classes_level_id_foreign / classes_school_id_foreign on a
        // fresh database and made the factory unusable in tests. Same fix as
        // StudentFactory and MembershipFactory.
        $levelId = Level::inRandomOrder()->value('id') ?? Level::factory()->create()->id;
        $schoolId = School::inRandomOrder()->value('id') ?? School::factory()->create()->id;

        return [
            'name' => $this->faker->randomElement(['2BAC SVT', '2BAC PC', 'BAC SVT']).' G'.$this->faker->unique()->numberBetween(1, 100),
            'level_id' => $levelId,
            'school_id' => $schoolId,
            'number_of_students' => $this->faker->numberBetween(20, 30),
            'number_of_teachers' => $this->faker->numberBetween(1, 3),
        ];
    }
}
