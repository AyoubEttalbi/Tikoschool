<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The definition was empty, so Invoice::factory() emitted an INSERT with only
     * timestamps and failed on the NOT NULL columns. Filled in so invoices can be built
     * in tests without hand-specifying every field.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $billDate = $this->faker->dateTimeBetween('-6 months', 'now');
        $month = $billDate->format('Y-m');
        $total = $this->faker->randomFloat(2, 100, 1000);

        $membership = Membership::inRandomOrder()->first();
        $studentId = $membership?->student_id
            ?? Student::inRandomOrder()->value('id')
            ?? Student::factory()->create()->id;

        return [
            'type' => 'invoice',
            'membership_id' => $membership?->id,
            'student_id' => $studentId,
            'offer_id' => $membership?->offer_id,
            'billDate' => $billDate,
            'creationDate' => $billDate,
            'endDate' => (clone $billDate)->modify('+1 month'),
            'months' => 1,
            'selected_months' => [$month],
            'totalAmount' => $total,
            'amountPaid' => $total,
            'rest' => 0,
            'includePartialMonth' => false,
            'partialMonthAmount' => null,
        ];
    }

    /** An invoice that has only been part-paid. */
    public function partiallyPaid(float $paid = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'amountPaid' => $paid,
            'rest' => max(0, (float) $attributes['totalAmount'] - $paid),
        ]);
    }
}
