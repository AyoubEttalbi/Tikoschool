<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'firstName' => $this->faker->firstName(),
            'lastName' => $this->faker->lastName(),
            'dateOfBirth' => $this->faker->date(),
            'billingDate' => $this->faker->date(),
            'address' => $this->faker->address(),
            'guardianNumber' => $this->faker->phoneNumber(),
            'CIN' => $this->faker->unique()->regexify('[A-Z0-9]{10}'),
            'phoneNumber' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'massarCode' => $this->faker->unique()->regexify('[A-Z0-9]{10}'),
            'levelId' => $this->faker->randomElement([1, 2]), // Assuming levels 1 and 2 exist
            'classId' => $this->faker->randomElement([2]), // Assuming classes 1 and 2 exist
            'schoolId' => $this->faker->randomElement([1, 2]), // Assuming schools 1 and 2 exist
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'assurance' => $this->faker->boolean(),
            'profile_image' =>$this->faker->imageUrl(640, 480, 'people'),
        ];
    }
}

