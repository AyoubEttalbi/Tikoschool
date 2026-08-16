<?php

use App\Models\Invoice;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * THE EARNINGS DASHBOARD AGAINST THE MONTHLY SUMMARY
 *
 * The reported screen: "Résumé mensuel" said 600 DH of expenses for a month while the
 * yearly cards beside it said "Dépenses totales 0,00 DH". Two panels, one database,
 * different answers.
 *
 * The dashboard built its month map from INVOICES only and queried expenses for the
 * months that map happened to contain — a month with spend but no revenue was
 * backfilled with a hardcoded 0 and never asked. These tests pin the three defects
 * that produced it: expense months missing from the map, partial-month revenue
 * attributed differently than the summary, and spend-only years missing from the
 * selector.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-16 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function earningsAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

/** One spend row in a month, nothing else. */
function spendIn(string $type, float $amount, string $date): Transaction
{
    return Transaction::create([
        'type' => $type,
        'amount' => $amount,
        'user_id' => User::factory()->create()->id,
        'user_name' => 'Test',
        'description' => 'test '.$type,
        'payment_date' => $date,
    ]);
}

/** The dashboard's month entry for a given year-month key, or null. */
function earningsMonth(User $admin, string $yearMonth): ?array
{
    $response = test()->actingAs($admin)
        ->get(route('admin.earnings.dashboard'))
        ->assertOk();

    $earnings = json_decode($response->getContent(), true)['earnings'];

    return collect($earnings)->firstWhere('yearMonth', $yearMonth);
}

it('counts a month\'s spend even when no invoice lands in that month', function () {
    spendIn('expense', 500.00, '2026-08-07');
    spendIn('expense', 100.00, '2026-08-09');
    spendIn('payment', 189.99, '2026-08-07');

    // No invoice anywhere near August 2026 — the reported shape exactly.
    $august = earningsMonth(earningsAdmin(), '2026-8');

    expect($august)->not->toBeNull()
        ->and((float) $august['totalExpenses'])->toBe(789.99)
        ->and((float) $august['totalRevenue'])->toBe(0.0)
        ->and((float) $august['profit'])->toBe(-789.99);
});

it('agrees with the monthly summary for the same month', function () {
    spendIn('expense', 600.00, '2026-08-07');
    spendIn('payment', 189.99, '2026-08-07');

    $student = Student::factory()->create(['status' => 'active']);
    Invoice::factory()->create([
        'student_id' => $student->id,
        'billDate' => '2026-08-01',
        'creationDate' => '2026-08-01',
        'selected_months' => '["2026-08"]',
        'amountPaid' => 330,
        'totalAmount' => 330,
        'rest' => 0,
    ]);

    $admin = earningsAdmin();
    $august = earningsMonth($admin, '2026-8');

    $summary = test()->actingAs($admin)
        ->get(route('admin.filtered.monthly.stats', ['month' => 8, 'year' => 2026]))
        ->assertOk();

    $stats = json_decode($summary->getContent(), true)['stats'];

    expect((float) $august['totalRevenue'])->toBe((float) $stats['totalRevenue'])
        ->and((float) $august['totalExpenses'])->toBe(
            (float) $stats['totalSalaries'] + (float) $stats['totalPayments'] + (float) $stats['totalExpenses']
        )
        ->and((float) $august['profit'])->toBe((float) $stats['profit']);
});

it('attributes a partial-month invoice the same way as the monthly summary', function () {
    $student = Student::factory()->create(['status' => 'active']);

    // Billed mid-month with a partial amount: the bill month joins the distribution in
    // the summary. The dashboard used to skip that month entirely.
    Invoice::factory()->create([
        'student_id' => $student->id,
        'billDate' => '2026-08-16',
        'creationDate' => '2026-08-16',
        'selected_months' => '["2026-09","2026-10"]',
        'amountPaid' => 300,
        'totalAmount' => 300,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 50,
    ]);

    $admin = earningsAdmin();
    $august = earningsMonth($admin, '2026-8');

    $summary = json_decode(test()->actingAs($admin)
        ->get(route('admin.filtered.monthly.stats', ['month' => 8, 'year' => 2026]))
        ->getContent(), true)['stats'];

    expect((float) $august['totalRevenue'])->toBe((float) $summary['totalRevenue']);
});

it('offers a year that only holds spend in the year selector', function () {
    spendIn('expense', 120.00, '2024-03-10');

    $response = test()->actingAs(earningsAdmin())
        ->get(route('admin.earnings.dashboard'))
        ->assertOk();

    $years = json_decode($response->getContent(), true)['availableYears'];

    expect(in_array(2024, $years, true))->toBeTrue();
});

it('carries months older than the last twelve, so a past year is complete', function () {
    $student = Student::factory()->create(['status' => 'active']);

    // Twenty months back: outside the dashboard's 12-month backfill window.
    Invoice::factory()->create([
        'student_id' => $student->id,
        'billDate' => '2024-12-01',
        'creationDate' => '2024-12-01',
        'selected_months' => '["2024-12"]',
        'amountPaid' => 400,
        'totalAmount' => 400,
        'rest' => 0,
    ]);

    $december = earningsMonth(earningsAdmin(), '2024-12');

    expect($december)->not->toBeNull()
        ->and((float) $december['totalRevenue'])->toBe(400.0);
});

it('excludes deleted invoices from the revenue distribution', function () {
    $student = Student::factory()->create(['status' => 'active']);

    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'billDate' => '2026-08-01',
        'creationDate' => '2026-08-01',
        'selected_months' => '["2026-08"]',
        'amountPaid' => 330,
        'totalAmount' => 330,
        'rest' => 0,
    ]);
    $invoice->delete();

    $august = earningsMonth(earningsAdmin(), '2026-8');

    expect((float) ($august['totalRevenue'] ?? 0))->toBe(0.0);
});

it('is closed to teachers', function () {
    test()->actingAs(User::factory()->create(['role' => 'teacher']))
        ->get(route('admin.earnings.dashboard'))
        ->assertRedirect();
});

it('shows real expenses on the payments page instead of a pinned zero', function () {
    /*
     * The payments screen used to render its cards from calculateAdminEarningsForComparison(),
     * which ran the inMonth() scope on DB::table() — a scope the query builder does not
     * have. BadMethodCallException, caught, converted to 0: every month card said
     * "Dépenses totales 0,00 DH" while the Résumé mensuel on the same screen counted the
     * very same transactions. This test pins the page payload that browsers actually saw.
     */
    spendIn('expense', 600.00, '2026-08-07');
    spendIn('payment', 189.99, '2026-08-07');

    test()->actingAs(earningsAdmin())
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Menu/PaymentsPage')
            ->has('adminEarnings.earnings', 8)
            ->where(
                'adminEarnings.earnings.0.totalExpenses',
                fn ($value) => abs((float) $value - 789.99) < 0.01,
            )
        );
});
