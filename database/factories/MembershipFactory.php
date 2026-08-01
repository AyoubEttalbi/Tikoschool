<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create the parents when none exist rather than falling back to a hardcoded id,
        // which violates the foreign keys on a fresh database.
        $studentId = Student::inRandomOrder()->value('id') ?? Student::factory()->create()->id;
        $offerId = Offer::inRandomOrder()->value('id') ?? Offer::factory()->create()->id;

        return [
            'student_id' => $studentId,
            'offer_id' => $offerId,
            // `teachers` is a JSON array of {teacherId, subject} objects — that is the shape
            // TeacherMembershipPaymentService::processTeacherPayment() reads. It used to be a
            // flat array of integers, which the payout engine cannot process at all.
            'teachers' => [],
            // Must be one of the column's enum values ('pending','paid','expired').
            // 'overdue' was in this list and is NOT valid — MySQL rejected/truncated it.
            'payment_status' => $this->faker->randomElement(['paid', 'pending', 'expired']),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'start_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'end_date' => $this->faker->dateTimeBetween('now', '+1 year'),
        ];
    }
}
