<?php

use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Student;
use App\Models\User;

it('renders the single invoice PDF (redesigned view)', function () {
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['level_id' => $level->id]);
    $student = Student::factory()->create(['classId' => $class->id, 'status' => 'active', 'levelId' => $level->id]);
    $membership = Membership::factory()->create(['student_id' => $student->id]);
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'billDate' => '2026-07-01',
        'amountPaid' => 350,
        'totalAmount' => 350,
    ]);

    $response = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('invoices.pdf', $invoice->id));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toStartWith('%PDF');

    // The two titles of the same paper: the school keeps FACTURE, the learner gets the
    // receipt. The rendered HTML is the contract the Blade owns.
    $html = view('invoices.invoice_pdf', [
        'invoice' => $invoice,
        'membership' => $membership,
        'student' => $student,
        'offerName' => $membership->offer?->offer_name ?? 'Offre',
    ])->render();

    expect($html)->toContain('Copie école')
        ->toContain('Copie élève')
        ->and(substr_count($html, 'FACTURE'))->toBe(1)
        ->and(substr_count($html, 'REÇU'))->toBe(1)
        ->and(substr_count($html, 'Prix du pack'))->toBe(1);
});
