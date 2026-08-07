<?php

namespace Tests\Support;

use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Support\Carbon;

/**
 * One complete money path, built the way a school builds it.
 *
 *   offer (percentage per subject)
 *     → membership (student + offer + teachers)
 *       → invoice (months, total, amount paid)
 *         → teacher_membership_payments (one per teacher-subject)
 *           → teachers.wallet, via teacher_wallet_entries
 *
 * Scenario tests are only useful if they exercise the SAME entry points the controllers
 * use. Every method here calls the real service — none of them reach into the tables to
 * fake a state the application could not itself produce. That is deliberate: a harness
 * that hand-writes payout rows proves nothing about the code that writes them in
 * production.
 *
 * Time is always pinned with Carbon::setTestNow() so "current month" and "future month"
 * are decidable. Without that, a suite run on the 31st or across a month boundary
 * produces different arithmetic than the same suite run on the 2nd.
 */
class PaymentScenario
{
    public Offer $offer;

    public Student $student;

    public Membership $membership;

    public ?Invoice $invoice = null;

    /** @var array<string, Teacher> subject => teacher */
    public array $teachers = [];

    /** Whatever the last service call reported, for assertions on failure paths. */
    public array $lastResult = [];

    private TeacherMembershipPaymentService $service;

    /**
     * @param  array<string, float>  $percentages  offer percentage map, e.g. ['Math' => 60]
     * @param  array<int, string>  $teacherSubjects  one teacher created per entry
     */
    public static function make(
        array $percentages = ['Math' => 50],
        ?array $teacherSubjects = null,
        float $price = 1000,
    ): self {
        $scenario = new self;
        $scenario->service = new TeacherMembershipPaymentService;

        // The offer needs a level; Level has no other dependency.
        $levelId = Level::query()->value('id') ?? Level::factory()->create()->id;

        $scenario->offer = Offer::factory()->create([
            'offer_name' => 'Offre test',
            'price' => $price,
            'levelId' => $levelId,
            'subjects' => array_keys($percentages),
            'percentage' => $percentages,
        ]);

        $scenario->student = Student::factory()->create();

        $subjects = $teacherSubjects ?? array_keys($percentages);
        $teacherRows = [];

        foreach ($subjects as $subject) {
            $teacher = Teacher::factory()->create(['wallet' => 0]);
            $scenario->teachers[$subject] = $teacher;
            $teacherRows[] = ['teacherId' => $teacher->id, 'subject' => $subject];
        }

        $scenario->membership = Membership::factory()->create([
            'student_id' => $scenario->student->id,
            'offer_id' => $scenario->offer->id,
            'teachers' => $teacherRows,
            'payment_status' => 'pending',
            'is_active' => true,
        ]);

        return $scenario;
    }

    /**
     * The student is billed and pays $paid of $total. This is InvoiceController::store().
     *
     * @param  array<int, string>  $months  e.g. ['2026-08', '2026-09']
     */
    public function bill(
        array $months,
        float $total,
        float $paid,
        ?string $billDate = null,
        bool $includePartialMonth = false,
        float $partialMonthAmount = 0,
    ): self {
        sort($months);

        $this->invoice = Invoice::factory()->create([
            'membership_id' => $this->membership->id,
            'student_id' => $this->student->id,
            'offer_id' => $this->offer->id,
            'billDate' => $billDate ?? Carbon::now(),
            'creationDate' => $billDate ?? Carbon::now(),
            'endDate' => Carbon::parse($billDate ?? Carbon::now())->addMonths(max(1, count($months))),
            'months' => count($months),
            'selected_months' => $months,
            'totalAmount' => $total,
            'amountPaid' => $paid,
            'rest' => round($total - $paid, 2),
            'includePartialMonth' => $includePartialMonth,
            'partialMonthAmount' => $partialMonthAmount > 0 ? $partialMonthAmount : null,
        ]);

        $this->lastResult = $this->service->processInvoicePayment(
            $this->invoice,
            $this->validatedPayload($total, $paid, $includePartialMonth, $partialMonthAmount)
        );

        return $this;
    }

    /**
     * The student comes back and pays more, and/or the clerk adds months.
     * This is InvoiceController::update() — process, then reconcile when the amount moved.
     *
     * @param  array<int, string>|null  $months  null keeps the invoice's current months
     */
    public function editInvoice(
        float $newTotal,
        float $newPaid,
        ?array $months = null,
        bool $includePartialMonth = false,
        float $partialMonthAmount = 0,
    ): self {
        $previousPaid = round((float) $this->invoice->amountPaid, 2);

        $months ??= $this->invoice->selected_months ?? [];
        sort($months);

        $this->invoice->update([
            'selected_months' => $months,
            'months' => count($months),
            'totalAmount' => $newTotal,
            'amountPaid' => $newPaid,
            'rest' => round($newTotal - $newPaid, 2),
            'includePartialMonth' => $includePartialMonth,
            'partialMonthAmount' => $partialMonthAmount > 0 ? $partialMonthAmount : null,
        ]);

        $this->invoice->refresh();

        $this->lastResult = $this->service->processInvoicePayment(
            $this->invoice,
            $this->validatedPayload($newTotal, $newPaid, $includePartialMonth, $partialMonthAmount)
        );

        // The controller only reconciles when the paid amount actually moved.
        if (round($newPaid, 2) !== $previousPaid) {
            $this->service->reconcilePaidMonthsForInvoice($this->invoice->fresh());
        }

        return $this;
    }

    /** Run the scheduled payout for a month, exactly as teachers:process-monthly-payments does. */
    public function runMonthlyCron(?string $month = null): array
    {
        return $this->service->processMonthlyPayments($month ?? Carbon::now()->format('Y-m'));
    }

    /**
     * Move the clock and run the cron for the month we land in — a month passing, in one call.
     */
    public function advanceToMonth(string $month, int $dayOfMonth = 1): self
    {
        $day = str_pad((string) $dayOfMonth, 2, '0', STR_PAD_LEFT);
        Carbon::setTestNow(Carbon::parse("{$month}-{$day} 09:00:00"));
        $this->runMonthlyCron($month);

        return $this;
    }

    /** Delete the invoice, going through the same reversal the controller triggers. */
    public function deleteInvoice(): array
    {
        $outcome = $this->service->reverseInvoicePayments($this->invoice);
        $this->invoice->delete();

        return is_array($outcome) ? $outcome : [];
    }

    // ---------------------------------------------------------------- assertions helpers

    public function wallet(string $subject): float
    {
        return round((float) $this->teachers[$subject]->fresh()->wallet, 2);
    }

    public function record(string $subject): ?TeacherMembershipPayment
    {
        return TeacherMembershipPayment::where('invoice_id', $this->invoice->id)
            ->where('teacher_id', $this->teachers[$subject]->id)
            ->where('teacher_subject', $subject)
            ->first();
    }

    /** The ledger is the source of truth; the wallet column is only a projection of it. */
    public function ledgerTotal(string $subject): float
    {
        return round((float) TeacherWalletEntry::where('teacher_id', $this->teachers[$subject]->id)->sum('amount'), 2);
    }

    public function ledgerReasons(string $subject): array
    {
        return TeacherWalletEntry::where('teacher_id', $this->teachers[$subject]->id)
            ->orderBy('id')
            ->pluck('reason')
            ->all();
    }

    /** What the teacher SHOULD hold: the student's payment times the teacher's share. */
    public function expectedCommission(string $subject, ?float $studentPaid = null): float
    {
        $paid = $studentPaid ?? round((float) $this->invoice->fresh()->amountPaid, 2);
        $pct = (float) ($this->offer->percentage[$subject] ?? 0);

        return round($paid * $pct / 100, 2);
    }

    private function validatedPayload(
        float $total,
        float $paid,
        bool $includePartialMonth,
        float $partialMonthAmount,
    ): array {
        return [
            'totalAmount' => $total,
            'amountPaid' => $paid,
            'rest' => round($total - $paid, 2),
            'includePartialMonth' => $includePartialMonth,
            'partialMonthAmount' => $partialMonthAmount,
        ];
    }
}
