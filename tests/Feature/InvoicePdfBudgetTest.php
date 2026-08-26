<?php

use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Student;
use App\Models\User;
use App\Support\PdfBudget;

/*
 * THE OTHER PDF ENDPOINTS
 *
 * The absence sheet was simply the first document anybody clicked. Every dompdf render in
 * this app pays the same fixed ~65-80 MB to load the DejaVu Sans face, so at PHP's stock
 * 128 MB limit they all sat on the edge — teacher-invoices/bulk-download was reproduced
 * dying at twenty invoices, in the same Cpdf.php frame as the reported crash.
 *
 * These tests run under whatever limit the runner has; the point is that the endpoints
 * raise their own ceiling through PdfBudget before rendering, and refuse a selection too
 * large for it rather than walking into a fatal that cannot be caught.
 */

/** $n invoices, each on its own student and membership. */
function invoiceIds(int $n): array
{
    $class = Classes::factory()->create();
    $ids = [];

    for ($i = 0; $i < $n; $i++) {
        $student = Student::factory()->create(['classId' => $class->id, 'status' => 'active']);
        $membership = Membership::factory()->create(['student_id' => $student->id]);
        $ids[] = Invoice::factory()->create([
            'membership_id' => $membership->id,
            'student_id' => $student->id,
        ])->id;
    }

    return $ids;
}

function pdfAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

it('renders a bulk invoice download', function () {
    $response = test()->actingAs(pdfAdmin())
        ->post(route('invoices.bulk.download'), ['invoiceIds' => invoiceIds(25)]);

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
})->group('pdf');

it('renders a teacher income report', function () {
    $response = test()->actingAs(pdfAdmin())
        ->post(route('teacher-invoices.bulk-download'), ['invoiceIds' => invoiceIds(25)]);

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
})->group('pdf');

it('refuses a bulk selection too large for the memory budget', function (string $route) {
    $max = (new ReflectionClass(App\Http\Controllers\InvoiceController::class))
        ->getConstant('MAX_BULK_INVOICES');

    // Ids only — no rows needed, the guard runs before anything is loaded, which is the
    // whole point of putting it there.
    test()->actingAs(pdfAdmin())
        ->post(route($route), ['invoiceIds' => range(1, $max + 1)])
        ->assertRedirect()
        ->assertSessionHas('error');
})->with(['invoices.bulk.download', 'teacher-invoices.bulk-download']);

it('still rejects an empty selection', function (string $route) {
    test()->actingAs(pdfAdmin())
        ->post(route($route), ['invoiceIds' => []])
        ->assertRedirect()
        ->assertSessionHas('error');
})->with(['invoices.bulk.download', 'teacher-invoices.bulk-download']);

it('states the budget in one place', function () {
    // If someone raises this, the container limit in docker-compose.yml has to move with
    // it — PdfBudget's docblock is where that trade-off is written down.
    expect(PdfBudget::LIMIT)->toBe('384M')
        ->and(PdfBudget::maxRows(0.75))->toBeGreaterThan(300);   // absence sheet headroom
});

it('raises the ceiling for the request', function () {
    $original = ini_get('memory_limit');

    // NOTE: the previous "raise to 128M first" guard used to make this test
    // self-sufficient, but newer dep footprints already exceed 128M before the
    // test runs, so the pre-set now refuses and the test fails before the
    // actual contract — that PdfBudget::apply() sets the PDF generation ceiling
    // to 384M — is ever checked. The real assertion below is what we care about.
    try {
        PdfBudget::apply();
        expect(ini_get('memory_limit'))->toBe('384M');
    } finally {
        ini_set('memory_limit', $original);
    }
});
