<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Support\OfferPercentages;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeacherMembershipPaymentService
{
    /**
     * How long after the billing date a deleted invoice may still be clawed back out of the
     * teachers' wallets.
     *
     * Deleting the invoice is ALWAYS allowed. What expires is the claw-back: past this many
     * days the teachers keep what they have already been paid, and whoever pressed delete is
     * told so explicitly (see reverseInvoicePayments()).
     *
     * ONE definition. The rule used to be written as a bare `<= 10` in two separate branches
     * of reverseTeacherPayment(), which is how the single-month and multi-month paths came to
     * disagree about what "already paid" meant.
     */
    public const REVERSAL_DEADLINE_DAYS = 7;

    private ?TeacherWalletService $walletService = null;

    /**
     * Every wallet movement in this class goes through here.
     *
     * TeacherWalletService writes an append-only ledger row and updates the cached
     * `teachers.wallet` projection in one locked transaction, and refuses duplicate
     * credits via a database unique constraint. Direct increment('wallet') /
     * decrement('wallet') calls bypass all of that — do not reintroduce them.
     */
    protected function wallet(): TeacherWalletService
    {
        return $this->walletService ??= new TeacherWalletService;
    }

    /**
     * Total percentage of a student's payment that an offer allocates across the teachers
     * actually attached to a membership.
     *
     * Only subjects that a teacher on this membership is assigned to count — an offer may
     * legitimately list percentages for subjects nobody on this membership teaches.
     */
    /**
     * The share of the student's payment that goes to the teacher of $teacherSubject.
     *
     * ONE definition, used by both the create path (processTeacherPayment) and the update
     * path (updateExistingRecord). They used to diverge: creation applied the
     * equal-distribution fallback below, the update path read
     * `$offer->percentage[$subject] ?? 0` with no fallback at all.
     *
     * The consequence was a silent claw-back of real money. A teacher whose subject had no
     * percentage entry was paid an equal share when the record was created, then
     * recalculated at 0% the first time anyone edited that invoice — and the difference was
     * written to their wallet as an adjustment. Nothing surfaced it.
     *
     * Keeping this in one method is the point; the divergence is what the bug was.
     */
    protected function resolveTeacherPercentage(Offer $offer, Membership $membership, ?string $teacherSubject): float
    {
        $percentages = is_array($offer->percentage) ? $offer->percentage : [];

        // Case- and whitespace-insensitive: subject names are typed by hand in two
        // different forms, and a membership storing "math" against an offer that says
        // "Math" used to resolve to 0%. @see \App\Support\OfferPercentages
        $declared = OfferPercentages::forSubject($offer, $teacherSubject);
        $teacherPercentage = round((float) ($declared ?? 0), 2);

        if ($teacherPercentage > 0) {
            return $teacherPercentage;
        }

        // No percentage defined for this subject: share out whatever the offer has not
        // already allocated — among the teachers who ALSO have no declared percentage.
        //
        // The divisor used to be every teacher on the membership, including the ones already
        // paid from their own declared share. With {"Math": 60} and a Math + Physique
        // membership that gave Physique 40/2 = 20%, so 60 + 20 = 80% of the student's payment
        // was distributed and the missing 20% simply evaporated — the school kept it and the
        // teacher was quietly underpaid, with the log line below reporting the wrong number
        // as if it were correct.
        $definedPercentagesSum = array_sum($percentages);
        $availableForDistribution = max(0, 100 - $definedPercentagesSum);
        $undeclaredCount = $this->teachersWithoutDeclaredPercentage($membership, $offer);

        if ($undeclaredCount > 0 && $availableForDistribution > 0) {
            $resolved = round($availableForDistribution / $undeclaredCount, 2);

            Log::info('Auto-assigned percentage to teacher with no defined percentage', [
                'teacher_subject' => $teacherSubject,
                'auto_assigned_percentage' => $resolved,
                'available_for_distribution' => $availableForDistribution,
                'teachers_without_declared_percentage' => $undeclaredCount,
                'offer_id' => $offer->id,
                'membership_id' => $membership->id,
            ]);

            return $resolved;
        }

        // Nothing is left to allocate, so this teacher's share is ZERO.
        //
        // This used to fall back to `100 / $teachersCount`, which allocated a share that
        // did not exist. An offer like {"Math": 100, "Physique": 0} with two teachers paid
        // 100% + 50% = 150% of the student's payment — every such invoice quietly
        // overpaid, and nothing downstream checked the total.
        Log::warning('No percentage left to allocate; teacher share set to 0', [
            'teacher_subject' => $teacherSubject,
            'defined_percentages_sum' => $definedPercentagesSum,
            'teachers_without_declared_percentage' => $undeclaredCount,
            'offer_id' => $offer->id,
            'membership_id' => $membership->id,
        ]);

        return 0.0;
    }

    /**
     * How many DISTINCT subjects on this membership the offer says nothing about.
     *
     * The divisor for the equal-distribution fallback. Counted by distinct subject rather
     * than by teacher row so that one teacher listed twice for the same subject cannot
     * shrink everybody else's share.
     */
    private function teachersWithoutDeclaredPercentage(Membership $membership, Offer $offer): int
    {
        if (! is_array($membership->teachers)) {
            return 0;
        }

        $undeclared = [];

        foreach ($membership->teachers as $teacherData) {
            $subject = is_array($teacherData) ? ($teacherData['subject'] ?? null) : null;

            if ($subject === null || $subject === '') {
                continue;
            }

            if ((float) (OfferPercentages::forSubject($offer, $subject) ?? 0) > 0) {
                continue;
            }

            $undeclared[OfferPercentages::normalise($subject)] = true;
        }

        return count($undeclared);
    }

    /**
     * A teacher's name for an error message, falling back to the id when the row is gone.
     *
     * Error messages get read by a secretary, not by a developer — "Majid FAYTI (Math)"
     * tells them which row to open; "teacher 41" does not.
     */
    private function teacherLabel(?int $teacherId): string
    {
        if (! $teacherId) {
            return 'Un enseignant';
        }

        $teacher = Teacher::find($teacherId);

        if (! $teacher) {
            return "L'enseignant #{$teacherId} (introuvable)";
        }

        return trim($teacher->first_name.' '.$teacher->last_name) ?: "L'enseignant #{$teacherId}";
    }

    protected function totalAllocatedPercentage(Membership $membership, Offer $offer): float
    {
        if (! is_array($offer->percentage) || ! is_array($membership->teachers)) {
            return 0.0;
        }

        $subjects = array_values(array_filter(array_map(
            fn ($t) => is_array($t) ? ($t['subject'] ?? null) : null,
            $membership->teachers
        )));

        // Same lookup rule as resolveTeacherPercentage(), or this 100% ceiling check would
        // read 0 for a subject the payout path values at 60 and never fire.
        $total = 0.0;
        foreach (array_unique($subjects) as $subject) {
            $total += (float) (OfferPercentages::forSubject($offer, $subject) ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Create or update teacher membership payment records for an invoice
     */
    public function processInvoicePayment(Invoice $invoice, array $validated)
    {
        $result = [
            'success' => false,
            'created_records' => 0,
            'updated_records' => 0,
            'errors' => [],
        ];

        try {
            // NEW: Validate before processing
            $validationResult = $this->validateBeforeProcessing($invoice, $validated);
            if (! $validationResult['valid']) {
                $result['errors'] = $validationResult['errors'];
                Log::error('Validation failed before processing invoice payment', [
                    'invoice_id' => $invoice->id,
                    'validation_errors' => $validationResult['errors'],
                ]);

                return $result;
            }

            $membership = $invoice->membership;
            if (! $membership || ! is_array($membership->teachers)) {
                $result['errors'][] = 'No membership or teachers found for invoice '.$invoice->id;

                return $result;
            }

            // NEW: Log validation success
            Log::info('Invoice payment validation passed', [
                'invoice_id' => $invoice->id,
                'validation_data' => $validationResult['validated_data'],
            ]);

            // Get selected months
            $selectedMonths = $invoice->selected_months ?? [];
            if (is_string($selectedMonths)) {
                $selectedMonths = json_decode($selectedMonths, true) ?? [];
            }

            // If partial month is included, automatically add current month to selected months
            $currentMonth = now()->format('Y-m');
            if (($validated['includePartialMonth'] ?? false) && ($validated['partialMonthAmount'] ?? 0) > 0) {
                if (! in_array($currentMonth, $selectedMonths)) {
                    $selectedMonths[] = $currentMonth;
                    // Sort months chronologically
                    sort($selectedMonths);
                }
            }

            // Fallback: if no selected_months after processing partial month, use the billDate month
            if (empty($selectedMonths)) {
                $selectedMonths = [$invoice->billDate ? $invoice->billDate->format('Y-m') : null];
            }

            // Calculate the percentage of the amount paid (cumulatively for the invoice) to the total invoice amount
            $paymentPercentage = 0;
            $totalInvoiceAmount = round((float) ($invoice->totalAmount ?? 0), 2);
            $amountPaidCumulative = round((float) ($invoice->amountPaid ?? 0), 2);

            if ($totalInvoiceAmount > 0) {
                $paymentPercentage = ($amountPaidCumulative / $totalInvoiceAmount) * 100;
            } else {
                // If totalAmount is 0 (unlikely for an invoice), but some amount is paid, consider it 100% paid.
                $paymentPercentage = $amountPaidCumulative > 0 ? 100 : 0;
            }

            // Cap payment percentage at 100% to prevent over-calculation
            $paymentPercentage = min($paymentPercentage, 100);
            $paymentPercentage = round($paymentPercentage, 2);

            // Debug logging for payment processing
            Log::info('Payment processing details', [
                'invoice_id' => $invoice->id,
                'validated_total_amount' => $validated['totalAmount'],
                'validated_amount_paid_current_transaction' => $validated['amountPaid'], // This is the current transaction amount
                'invoice_total_amount_cumulative' => $totalInvoiceAmount, // Total invoice value
                'invoice_amount_paid_cumulative' => $amountPaidCumulative, // Cumulative paid for invoice
                'include_partial_month' => $validated['includePartialMonth'] ?? false,
                'partial_month_amount' => round((float) ($validated['partialMonthAmount'] ?? 0), 2),
                'payment_percentage' => $paymentPercentage,
                'original_selected_months' => $invoice->selected_months,
                'final_selected_months' => $selectedMonths,
                'current_month' => $currentMonth,
                'current_month_added_to_selected' => in_array($currentMonth, $selectedMonths),
            ]);

            // Process each teacher
            foreach ($membership->teachers as $teacherData) {
                $this->processTeacherPayment(
                    $teacherData,
                    $membership,
                    $invoice,
                    $selectedMonths,
                    $paymentPercentage,
                    $validated,
                    round((float) ($validated['partialMonthAmount'] ?? 0), 2)
                );
            }

            // Validate that payment records were created
            $createdRecords = TeacherMembershipPayment::where('invoice_id', $invoice->id)->count();
            if ($createdRecords === 0) {
                $result['errors'][] = 'No payment records were created for invoice '.$invoice->id;
                Log::error('No payment records created', ['invoice_id' => $invoice->id]);
            } else {
                $result['success'] = true;
                $result['created_records'] = $createdRecords;
                Log::info('Payment records created successfully', [
                    'invoice_id' => $invoice->id,
                    'created_records' => $createdRecords,
                ]);
            }

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('Error in payment processing', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $result;
    }

    /**
     * Process payment for a specific teacher
     */
    private function processTeacherPayment(
        array $teacherData,
        Membership $membership,
        Invoice $invoice,
        array $selectedMonths,
        float $paymentPercentage,
        array $validated,
        float $partialMonthAmount = 0
    ) {
        $teacher = Teacher::find($teacherData['teacherId']);
        if (! $teacher) {
            return;
        }

        // Get teacher subject and percentage from offer
        $offer = $membership->offer;
        $teacherSubject = $teacherData['subject'] ?? null;

        // If subject is not provided in teacherData, try to map it from offer percentages
        if (! $teacherSubject && $offer && is_array($offer->percentage)) {
            $subjects = array_keys($offer->percentage);
            $teacherIndex = array_search($teacherData['teacherId'], array_column($membership->teachers, 'teacherId'));
            if ($teacherIndex !== false && isset($subjects[$teacherIndex])) {
                $teacherSubject = $subjects[$teacherIndex];
            }
        }

        if (! $offer || ! $teacherSubject || ! is_array($offer->percentage)) {
            Log::warning('Cannot process teacher payment - missing subject or offer data', [
                'teacher_id' => $teacherData['teacherId'],
                'teacher_subject' => $teacherSubject,
                'offer_id' => $offer->id ?? null,
                'membership_id' => $membership->id,
                'offer_percentages' => $offer ? $offer->percentage : null,
            ]);

            return;
        }

        $teacherPercentage = $this->resolveTeacherPercentage($offer, $membership, $teacherSubject);

        // Hard invariant: the sum of all shares for this membership can never exceed 100%.
        // Checked AFTER the fallback, because the fallback is what used to breach it.
        $allocated = $this->totalAllocatedPercentage($membership, $offer);
        if ($allocated > 100.01) {
            Log::error('Offer allocates more than 100% across its teachers — payment refused', [
                'offer_id' => $offer->id,
                'membership_id' => $membership->id,
                'allocated_percentage' => $allocated,
                'percentages' => $offer->percentage,
            ]);

            throw new \RuntimeException(
                "L'offre « {$offer->offer_name} » répartit {$allocated}% entre ses enseignants "
                .'(le total ne peut pas dépasser 100%). Corrigez les pourcentages de l\'offre.'
            );
        }

        // 1. Calculate total teacher amount based on student's CUMULATIVE payment × teacher percentage
        $studentTotalPaidCumulative = round((float) ($invoice->amountPaid ?? 0), 2);
        $totalTeacherAmount = round(($studentTotalPaidCumulative * $teacherPercentage / 100), 2);

        $currentMonth = now()->format('Y-m');
        $isCurrentMonthIncluded = in_array($currentMonth, $selectedMonths);

        // 2. Calculate amount for current month (immediate payment)
        $immediateWalletAmount = 0;
        if ($isCurrentMonthIncluded) {
            if (($validated['includePartialMonth'] ?? false) && $partialMonthAmount > 0) {
                $immediateWalletAmount = round(($partialMonthAmount * $teacherPercentage / 100), 2);
            } else {
                // Current month is selected but no specific partial amount, means full month paid immediately
                // Use the same logic as frontend: if includePartialMonth is true but partialMonthAmount is 0,
                // still use the partial month logic with the full amount
                if (($validated['includePartialMonth'] ?? false)) {
                    // Even if partialMonthAmount is 0, use the full amountPaid for partial month calculation
                    $immediateWalletAmount = round(($studentTotalPaidCumulative * $teacherPercentage / 100), 2);
                } else {
                    // No partial month, use normal division
                    $allSelectedMonthsCount = count($selectedMonths);
                    $actualMonthlyShare = $allSelectedMonthsCount > 0 ? round(($totalTeacherAmount / $allSelectedMonthsCount), 2) : 0;
                    $immediateWalletAmount = $actualMonthlyShare;
                }
            }
        }

        // 3. Monthly amount for scheduled payments (future months)
        $futureMonths = array_filter($selectedMonths, function ($month) use ($currentMonth) {
            return $month > $currentMonth;
        });
        $futureMonthsCount = count($futureMonths);
        $monthlyTeacherAmount = 0;
        if ($futureMonthsCount > 0) {
            $remainingAmountForFutureMonths = round(($totalTeacherAmount - $immediateWalletAmount), 2);
            $monthlyTeacherAmount = round(($remainingAmountForFutureMonths / $futureMonthsCount), 2);
        }

        // Debug logging
        Log::info('Teacher payment calculation details', [
            'teacher_id' => $teacher->id,
            'teacher_subject' => $teacherSubject,
            'teacher_percentage' => $teacherPercentage,
            'student_total_paid_cumulative' => $studentTotalPaidCumulative,
            'selected_months' => $selectedMonths,
            'all_selected_months_count' => count($selectedMonths),
            'future_months' => $futureMonths,
            'future_months_count' => $futureMonthsCount,
            'current_month' => $currentMonth,
            'is_current_month_included' => $isCurrentMonthIncluded,
            'calculated_immediate_amount' => $immediateWalletAmount,
            'total_teacher_amount' => $totalTeacherAmount,
            'monthly_teacher_amount' => $monthlyTeacherAmount,
            'partial_month_amount_input' => $partialMonthAmount,
            'include_partial_month' => $validated['includePartialMonth'] ?? false,
            'total_teacher_amount_formula' => "($studentTotalPaidCumulative × $teacherPercentage / 100)",
            'immediate_amount_formula' => ($isCurrentMonthIncluded ? (($partialMonthAmount > 0) ? "($partialMonthAmount × $teacherPercentage / 100)" : "$totalTeacherAmount / ".count($selectedMonths)) : '0'),
            'monthly_amount_formula' => ($futureMonthsCount > 0 ? "($totalTeacherAmount - $immediateWalletAmount) / $futureMonthsCount" : '0'),
        ]);

        // Record identity is (invoice, teacher, SUBJECT).
        //
        // It used to be just (teacher, invoice), so a teacher listed twice on the same
        // membership for two different subjects collided on one record: the second subject
        // overwrote the first and the teacher was paid for only one of the two.
        $existingRecord = TeacherMembershipPayment::where('teacher_id', $teacher->id)
            ->where('invoice_id', $invoice->id)
            ->where('teacher_subject', $teacherSubject)
            ->first();

        if ($existingRecord) {
            Log::info('Found existing record - updating', [
                'record_id' => $existingRecord->id,
                'teacher_id' => $teacher->id,
                'invoice_id' => $invoice->id,
                'existing_payment_percentage' => $existingRecord->payment_percentage,
                'new_payment_percentage' => $paymentPercentage,
                'was_inactive' => ! $existingRecord->is_active,
            ]);
            if (! $existingRecord->is_active) {
                $existingRecord->update(['is_active' => true]);
                Log::info('Reactivated inactive record', ['record_id' => $existingRecord->id]);
            }
            $this->updateExistingRecord($existingRecord, $selectedMonths, $totalTeacherAmount, $monthlyTeacherAmount, $paymentPercentage, $immediateWalletAmount, $partialMonthAmount, $validated);
        } else {
            Log::info('No existing record found - creating new', [
                'teacher_id' => $teacher->id,
                'invoice_id' => $invoice->id,
                'payment_percentage' => $paymentPercentage,
            ]);
            $this->createNewRecord(
                $teacher,
                $membership,
                $invoice,
                $selectedMonths,
                $totalTeacherAmount,
                $monthlyTeacherAmount,
                $paymentPercentage,
                $teacherSubject,
                $teacherPercentage,
                $immediateWalletAmount, // Pass calculated immediateWalletAmount
                $partialMonthAmount,
                $validated
            );
        }
    }

    /**
     * Reactivate payment records for fully paid invoices
     */
    public function reactivatePaymentRecords(Invoice $invoice)
    {
        $result = [
            'success' => false,
            'reactivated_records' => 0,
            'errors' => [],
        ];

        try {
            // Only reactivate if invoice is fully paid
            if ($invoice->amountPaid < $invoice->totalAmount) {
                $result['errors'][] = 'Invoice is not fully paid (amountPaid: '.$invoice->amountPaid.', totalAmount: '.$invoice->totalAmount.')';

                return $result;
            }

            $records = TeacherMembershipPayment::where('invoice_id', $invoice->id)
                ->where('is_active', false)
                ->get();

            foreach ($records as $record) {
                $record->update([
                    'is_active' => true,
                    'months_rest_not_paid_yet' => [], // Clear unpaid months for fully paid invoices
                ]);

                Log::info('Reactivated payment record', [
                    'record_id' => $record->id,
                    'invoice_id' => $invoice->id,
                    'teacher_id' => $record->teacher_id,
                ]);
            }

            $result['success'] = true;
            $result['reactivated_records'] = $records->count();

            Log::info('Payment records reactivated', [
                'invoice_id' => $invoice->id,
                'reactivated_count' => $records->count(),
            ]);

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('Error reactivating payment records', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Create a new teacher membership payment record
     */
    private function createNewRecord(
        Teacher $teacher,
        Membership $membership,
        Invoice $invoice,
        array $selectedMonths,
        float $totalTeacherAmount,
        float $monthlyTeacherAmount,
        float $paymentPercentage,
        string $teacherSubject,
        float $teacherPercentage,
        float $immediateWalletAmount, // Now directly receive this
        float $partialMonthAmount = 0,
        array $validated = []
    ) {
        $currentMonth = now()->format('Y-m');

        // Check if current month is included in selected months
        $isCurrentMonthIncluded = in_array($currentMonth, $selectedMonths);

        // The immediateWalletAmount is now calculated in processTeacherPayment
        // No need to recalculate here. Just ensure wallet increment if needed.

        if ($immediateWalletAmount > 0) {
            // Ledgered + idempotent: a repeat call for the same (teacher, invoice, month,
            // reason) is rejected by a unique constraint instead of paying twice.
            $this->wallet()->credit(
                $teacher,
                $immediateWalletAmount,
                \App\Models\TeacherWalletEntry::REASON_IMMEDIATE,
                null,
                $currentMonth,
                $invoice->id,
                null,
                // Part of the idempotency key. Without it, one teacher holding two
                // subjects on the same membership had their second credit silently
                // rejected as a duplicate and was underpaid with no error raised.
                $teacherSubject
            );
            Log::info('Immediately incremented teacher wallet for current month (creation)', [
                'teacher_id' => $teacher->id,
                'amount' => $immediateWalletAmount,
                'month' => $currentMonth,
                'partial_month_amount' => round($partialMonthAmount, 2),
                'teacher_percentage' => round($teacherPercentage, 2),
                'is_partial_month' => ($validated['includePartialMonth'] ?? false) && $partialMonthAmount > 0,
            ]);
        }

        // Future months will be handled by scheduled payments
        $futureMonths = array_filter($selectedMonths, function ($month) use ($currentMonth) {
            return $month > $currentMonth;
        });

        // If current month is included, it's already paid, so only future months remain unpaid
        // If current month is not included, all selected months are unpaid
        $unpaidMonths = array_values($futureMonths); // Always use future months as unpaid if current is handled.

        TeacherMembershipPayment::create([
            'student_id' => $membership->student_id,
            'teacher_id' => $teacher->id,
            'membership_id' => $membership->id,
            'invoice_id' => $invoice->id,
            'selected_months' => $selectedMonths,
            'months_rest_not_paid_yet' => $unpaidMonths, // Only future months
            'total_teacher_amount' => round($totalTeacherAmount, 2),
            'monthly_teacher_amount' => round($monthlyTeacherAmount, 2),
            'payment_percentage' => round($paymentPercentage, 2),
            'teacher_subject' => $teacherSubject,
            'teacher_percentage' => round($teacherPercentage, 2),
            'immediate_wallet_amount' => round($immediateWalletAmount, 2), // Store the immediate amount
            'total_paid_to_teacher' => round($immediateWalletAmount, 2), // Initial payment (immediate amount)
            'is_active' => true,
        ]);

        Log::info('Created teacher membership payment record', [
            'teacher_id' => $teacher->id,
            'membership_id' => $membership->id,
            'invoice_id' => $invoice->id,
            'total_amount' => round($totalTeacherAmount, 2),
            'monthly_amount' => round($monthlyTeacherAmount, 2),
            'selected_months' => $selectedMonths,
            'current_month' => $currentMonth,
            'is_current_month_included' => $isCurrentMonthIncluded,
            'current_month_paid_immediately' => $isCurrentMonthIncluded,
            'immediate_wallet_amount' => round($immediateWalletAmount, 2),
            'partial_month_amount' => round($partialMonthAmount, 2),
            'include_partial_month' => $validated['includePartialMonth'] ?? false,
            'unpaid_months_count' => count($unpaidMonths),
            'future_months_remaining' => $futureMonths,
            'unpaid_months_record' => $unpaidMonths,
        ]);
    }

    /**
     * Update an existing teacher membership payment record
     */
    private function updateExistingRecord(
        TeacherMembershipPayment $record,
        array $selectedMonths,
        float $totalTeacherAmount, // This is the total amount from the original processTeacherPayment, not the new one.
        float $monthlyTeacherAmount, // Same here, this is the original monthly amount.
        float $paymentPercentage,
        float $immediateWalletAmountFromCall, // This is the immediate amount calculated in processTeacherPayment
        float $partialMonthAmount = 0,
        array $validated = [] // Add validated to parameter list
    ) {
        // Row lock when a transaction is already open (processInvoicePayment opens one).
        // Same reasoning as processMonthlyPayments(): this method reads the record's
        // totals, computes deltas in PHP and writes them back, so a concurrent write
        // between the read and the write is lost.
        if (DB::transactionLevel() > 0) {
            $locked = TeacherMembershipPayment::whereKey($record->id)->lockForUpdate()->first();

            if ($locked) {
                $record = $locked;
            }
        }

        // Merge new selected months with existing ones
        $allSelectedMonths = array_unique(array_merge($record->selected_months ?? [], $selectedMonths));
        sort($allSelectedMonths); // Ensure months are sorted

        $offer = $record->membership->offer;

        // Shared with the creation path. This line used to be a bare
        // `$offer->percentage[$record->teacher_subject] ?? 0` with no fallback, so a
        // teacher paid by equal distribution at creation was recalculated at 0% here —
        // and the delta was debited from their wallet. See resolveTeacherPercentage().
        $teacherPercentage = $this->resolveTeacherPercentage($offer, $record->membership, $record->teacher_subject);

        // Get the current invoice to know the total amount paid by student
        $currentInvoice = $record->invoice;
        $studentTotalPaid = round((float) ($currentInvoice->amountPaid ?? 0), 2);

        // 1. Calculate total teacher amount based on student's payment × teacher percentage
        $newTotalAmount = round(($studentTotalPaid * $teacherPercentage / 100), 2);

        $currentMonth = now()->format('Y-m');
        $isCurrentMonthIncluded = in_array($currentMonth, $allSelectedMonths);

        // Get the old immediate wallet amount from the record
        $oldImmediateWalletAmount = round((float) ($record->immediate_wallet_amount ?? 0), 2);
        $oldTotalPaidToTeacher = round((float) ($record->total_paid_to_teacher ?? 0), 2);

        // 2. Calculate new immediate wallet amount for current month
        $newImmediateWalletAmount = 0;
        if ($isCurrentMonthIncluded) {
            if (($validated['includePartialMonth'] ?? false) && $partialMonthAmount > 0) {
                // Student chose to pay for current month with partial amount
                $newImmediateWalletAmount = round(($partialMonthAmount * $teacherPercentage / 100), 2);
            } else {
                // Current month is selected but no specific partial amount, means full month paid immediately
                // Calculate actual monthly teacher amount based on total paid and all months
                $allSelectedMonthsCount = count($allSelectedMonths);
                $actualMonthlyShare = $allSelectedMonthsCount > 0 ? round(($newTotalAmount / $allSelectedMonthsCount), 2) : 0;
                $newImmediateWalletAmount = $actualMonthlyShare;
            }
        }

        // 3. Calculate the difference in immediate wallet amount
        $walletDifference = round(($newImmediateWalletAmount - $oldImmediateWalletAmount), 2);

        // Only modify wallet if there's a difference
        if ($walletDifference != 0) {
            $teacher = Teacher::find($record->teacher_id);
            if ($teacher) {
                $teacherWalletBefore = round((float) ($teacher->wallet), 2);

                // A delta adjustment, so it must NOT be deduplicated against the original
                // immediate credit — hence a distinct reason and a per-update note.
                $this->wallet()->{$walletDifference > 0 ? 'credit' : 'debit'}(
                    $teacher,
                    abs($walletDifference),
                    \App\Models\TeacherWalletEntry::REASON_ADJUSTMENT,
                    $record->id,
                    null,
                    // Was `$invoice->id ?? null` — $invoice is not a parameter of this
                    // method and is never assigned in it, so `?? null` always won and
                    // EVERY adjustment entry was written with invoice_id = NULL. Not a
                    // double-pay risk (null invoice_id is deliberately exempt from the
                    // idempotency key) but those ledger rows could no longer be joined
                    // back to the invoice that caused them, which is what payouts:audit
                    // and any manual reconciliation rely on.
                    $currentInvoice?->id,
                    'immediate amount adjusted on invoice update (record '.$record->id.')',
                    $record->teacher_subject
                );

                Log::info('Adjusted teacher wallet after invoice update', [
                    'teacher_id' => $teacher->id,
                    'old_immediate_amount' => $oldImmediateWalletAmount,
                    'new_immediate_amount' => $newImmediateWalletAmount,
                    'difference' => $walletDifference,
                    'wallet_before_op' => $teacherWalletBefore,
                    'wallet_after_op' => round((float) ($teacher->fresh()->wallet), 2),
                ]);
            }
        } else {
            Log::info('No wallet change needed in update - immediate amount unchanged', [
                'teacher_id' => $record->teacher_id,
                'old_immediate_amount' => $oldImmediateWalletAmount,
                'new_immediate_amount' => $newImmediateWalletAmount,
                'difference' => $walletDifference,
            ]);
        }

        // 4. Calculate monthly amount for scheduled payments (future months only)
        // This MUST be calculated AFTER newImmediateWalletAmount is finalized
        $futureMonths = array_filter($allSelectedMonths, function ($month) use ($currentMonth) {
            return $month > $currentMonth;
        });
        $futureMonthsCount = count($futureMonths);

        $newMonthlyAmount = 0;
        if ($futureMonthsCount > 0) {
            $remainingAmountForFutureMonths = round(($newTotalAmount - $newImmediateWalletAmount), 2);
            $newMonthlyAmount = round(($remainingAmountForFutureMonths / $futureMonthsCount), 2);
        }

        // Rebuild unpaid months safely:
        // - Start with existing unpaid months
        // - Add only NEW future months from selectedMonths
        // - Never re-add past or current months as unpaid
        $existingUnpaid = is_array($record->months_rest_not_paid_yet) ? $record->months_rest_not_paid_yet : [];
        $existingUnpaid = array_values(array_unique($existingUnpaid));
        $existingSelected = is_array($record->selected_months) ? $record->selected_months : [];

        // Only consider months newly added in this update
        $newlyAddedMonths = array_values(array_diff($selectedMonths, $existingSelected));

        // Only future newly-added months can be marked unpaid
        $newUnpaid = array_values(array_filter($newlyAddedMonths, function ($month) use ($currentMonth) {
            return $month > $currentMonth;
        }));

        // Preserve existing unpaid months and append new future months
        $unpaidMonths = array_values(array_unique(array_merge($existingUnpaid, $newUnpaid)));

        // IMPROVED BACK-PAY LOGIC: Handle past monthly withdrawals correctly
        // Process any outstanding payments for past months that need immediate payment
        $isCurrentMonthIncluded = in_array($currentMonth, $allSelectedMonths);

        if ($isCurrentMonthIncluded && $newImmediateWalletAmount > 0) {
            // Current month immediate payment is already handled in the immediate wallet update above
            Log::info('Current month immediate payment processed', [
                'record_id' => $record->id,
                'current_month' => $currentMonth,
                'immediate_amount' => $newImmediateWalletAmount,
            ]);
        }

        // CRITICAL FIX: Don't automatically remove past months from unpaid
        // Past months should remain unpaid until invoice is fully paid
        // Only handle back-payment for past months that are being paid now
        $billMonth = $record->invoice && $record->invoice->billDate ? $record->invoice->billDate->format('Y-m') : null;
        if ($billMonth && $billMonth < $currentMonth && $studentTotalPaid > 0) {
            // Calculate proportional payment for past months
            $monthsCountForPastPayment = count(array_filter($allSelectedMonths, fn ($m) => $m < $currentMonth));
            $totalPastPaymentAmount = $studentTotalPaid * $teacherPercentage / 100;

            // Distribute past payment across past months
            if ($monthsCountForPastPayment > 0) {
                $pastMonthShare = round($totalPastPaymentAmount / $monthsCountForPastPayment, 2);

                $teacher = Teacher::find($record->teacher_id);
                if ($teacher && $pastMonthShare > 0) {
                    Log::info('Past month proportional payment calculated', [
                        'record_id' => $record->id,
                        'bill_month' => $billMonth,
                        'past_months_count' => $monthsCountForPastPayment,
                        'total_past_payment' => $totalPastPaymentAmount,
                        'per_past_month_share' => $pastMonthShare,
                        'all_past_months' => array_filter($allSelectedMonths, fn ($m) => $m < $currentMonth),
                    ]);

                    // Note: Past month payment is already included in newImmediateWalletAmount calculation
                    // No need to duplicate wallet increment here
                }
            }
        }

        // Calculate new total paid to teacher (cumulative: immediate + already processed scheduled payments)
        // We subtract the old immediate amount and add the new one, keeping previous scheduled payments.
        $newTotalPaidToTeacher = round((($oldTotalPaidToTeacher - $oldImmediateWalletAmount) + $newImmediateWalletAmount), 2);

        // Final clamp to [0, newTotalAmount]
        if ($newTotalPaidToTeacher < 0) {
            $newTotalPaidToTeacher = 0.0;
        }
        if ($newTotalPaidToTeacher > $newTotalAmount) {
            $newTotalPaidToTeacher = $newTotalAmount;
        }

        $record->update([
            'selected_months' => $allSelectedMonths,
            'months_rest_not_paid_yet' => $unpaidMonths,
            'total_teacher_amount' => $newTotalAmount,
            'monthly_teacher_amount' => $newMonthlyAmount,
            'payment_percentage' => $paymentPercentage,
            'immediate_wallet_amount' => $newImmediateWalletAmount, // Recalculated, not added
            'total_paid_to_teacher' => $newTotalPaidToTeacher, // Recalculated, cumulative and clamped
            'is_active' => true, // Ensure record stays active for potential updates
        ]);

        Log::info('Updated teacher membership payment record', [
            'record_id' => $record->id,
            'new_total_amount' => $newTotalAmount,
            'new_monthly_amount' => $newMonthlyAmount,
            'all_selected_months' => $allSelectedMonths,
            'current_month_paid_immediately' => $isCurrentMonthIncluded,
            'unpaid_months_count' => count($unpaidMonths),
            'immediate_wallet_amount_before_record' => $oldImmediateWalletAmount,
            'immediate_wallet_amount_after_record' => $newImmediateWalletAmount,
            'total_paid_to_teacher_before_record' => $oldTotalPaidToTeacher,
            'total_paid_to_teacher_after_record' => $newTotalPaidToTeacher, // Log cumulative
            'wallet_difference_applied' => $walletDifference,
            'record_reactivated' => true,
            'recalculation_details' => [
                'old_total_amount' => $record->total_teacher_amount,
                'student_total_paid_cumulative' => $studentTotalPaid,
                'teacher_percentage' => $teacherPercentage,
                'total_selected_months_count' => count($allSelectedMonths),
                'total_teacher_amount_formula' => "($studentTotalPaid × $teacherPercentage / 100)",
                'monthly_amount_calculation' => [
                    'future_months_count' => $futureMonthsCount,
                    'remaining_amount_for_future_months' => round(($newTotalAmount - $newImmediateWalletAmount), 2),
                    'monthly_amount_formula' => ($futureMonthsCount > 0 ? "($newTotalAmount - $newImmediateWalletAmount) / $futureMonthsCount" : '0'),
                ],
                'immediate_calculation_details' => [
                    'current_month_included' => $isCurrentMonthIncluded,
                    'partial_month_amount_input' => round($partialMonthAmount, 2),
                    'calculated_immediate_amount' => $newImmediateWalletAmount,
                    'reason' => ($isCurrentMonthIncluded ? (($partialMonthAmount > 0) ? 'partial_month_payment' : 'full_current_month_payment') : 'no_current_month_payment'),
                ],
                'total_paid_to_teacher_cumulative_calc' => "($oldTotalPaidToTeacher - $oldImmediateWalletAmount) + $newImmediateWalletAmount",
            ],
        ]);
    }

    /**
     * Clean up duplicate records for the same invoice and teacher
     * This method should be called manually to fix existing duplicates
     */
    public function cleanupDuplicateRecords()
    {
        Log::info('Starting cleanup of duplicate teacher membership payment records');

        // Grouped by (invoice, teacher, SUBJECT) to match the record identity used everywhere
        // else. Grouping by (invoice, teacher) alone treated a teacher's two subjects on the
        // same invoice as duplicates and deleted one of them — destroying a real payout.
        $duplicates = DB::table('teacher_membership_payments')
            ->select('invoice_id', 'teacher_id', 'teacher_subject', DB::raw('COUNT(*) as count'))
            ->whereNotNull('invoice_id')
            ->groupBy('invoice_id', 'teacher_id', 'teacher_subject')
            ->having('count', '>', 1)
            ->get();

        $cleanedCount = 0;

        foreach ($duplicates as $duplicate) {
            $records = TeacherMembershipPayment::where('invoice_id', $duplicate->invoice_id)
                ->where('teacher_id', $duplicate->teacher_id)
                ->where('teacher_subject', $duplicate->teacher_subject)
                // Keep the record the teacher has actually been paid the most against, so
                // deleting the others cannot orphan money already in a wallet.
                ->orderByDesc('total_paid_to_teacher')
                ->orderByDesc('created_at')
                ->get();

            $keepRecord = $records->first();
            $deleteRecords = $records->slice(1);

            foreach ($deleteRecords as $deleteRecord) {
                Log::info('Deleting duplicate record', [
                    'duplicate_id' => $deleteRecord->id,
                    'invoice_id' => $duplicate->invoice_id,
                    // $duplicate is a stdClass from DB::table(), so it has no `teacher`
                    // relation — `$duplicate->teacher->id` threw here and aborted the cleanup.
                    'teacher_id' => $duplicate->teacher_id,
                    'teacher_subject' => $duplicate->teacher_subject,
                    'discarded_total_paid' => $deleteRecord->total_paid_to_teacher,
                    'kept_record_id' => $keepRecord->id,
                ]);
                $deleteRecord->delete();
                $cleanedCount++;
            }
        }

        Log::info('Completed cleanup of duplicate records', [
            'duplicates_found' => $duplicates->count(),
            'records_deleted' => $cleanedCount,
        ]);

        return [
            'duplicates_found' => $duplicates->count(),
            'records_deleted' => $cleanedCount,
        ];
    }

    /**
     * Process monthly payments for all teachers
     * This should be called by a scheduled job at the start of each month
     */
    public function processMonthlyPayments($currentMonth = null)
    {
        $currentMonth = $currentMonth ?? now()->format('Y-m');

        Log::info('Starting monthly teacher payment processing', ['month' => $currentMonth]);

        $records = TeacherMembershipPayment::active()
            ->withUnpaidCurrentMonth($currentMonth)
            ->with(['teacher', 'membership', 'student'])
            ->get();

        $processedCount = 0;
        $totalAmount = 0;

        foreach ($records as $record) {
            try {
                DB::beginTransaction();

                // Re-read the payout record under a row lock inside the transaction.
                //
                // TeacherWalletService locks the `teachers` row, but the payout record's OWN
                // bookkeeping — total_paid_to_teacher, months_rest_not_paid_yet — was read
                // outside any lock, mutated in PHP and written back. The monthly cron racing
                // an admin's invoice edit (or two overlapping cron runs, since
                // withoutOverlapping only guards the schedule, not this loop) lost one
                // update: the ledger stayed correct while the record's totals drifted away
                // from it.
                //
                // Re-reading also picks up any change committed since the collection was
                // fetched, so the deltas below are computed from current state.
                $record = TeacherMembershipPayment::whereKey($record->id)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    DB::commit();

                    continue;
                }

                // Increment teacher wallet
                $teacher = $record->teacher;
                $monthlyAmount = round((float) $record->monthly_teacher_amount, 2);

                // STOP if this record is already settled.
                //
                // The selection above matches on withUnpaidCurrentMonth() alone — whether a
                // month is still listed in months_rest_not_paid_yet. It never asked whether
                // the teacher had ALREADY been paid in full. A record can end up fully paid
                // with months still queued (an invoice edit that recalculated the total, a
                // reconcile that paid everything up front), and then the cron pays it again
                // when one of those months comes round.
                //
                // The ledger does NOT catch this. Its key is
                // (teacher, invoice, month, reason, subject), and a credit for a month that
                // was never previously paid is a genuinely NEW row, not a duplicate.
                //
                // Found live: invoice 842, three teachers, each owed 270 and paid 270, each
                // still carrying 2026-09 and 2026-10 at 90/month. That was 540 about to be
                // paid twice over the following two cron runs.
                //
                // Clearing the month as well as skipping the credit means the stale queue
                // drains itself instead of re-presenting every month.
                $alreadyPaid = round((float) $record->total_paid_to_teacher, 2);
                $owed = round((float) $record->total_teacher_amount, 2);

                if ($owed > 0 && $alreadyPaid >= $owed - 0.01) {
                    Log::warning('Monthly payout skipped: record is already paid in full', [
                        'record_id' => $record->id,
                        'teacher_id' => $record->teacher_id,
                        'invoice_id' => $record->invoice_id,
                        'month' => $currentMonth,
                        'total_teacher_amount' => $owed,
                        'total_paid_to_teacher' => $alreadyPaid,
                        'would_have_paid' => $monthlyAmount,
                    ]);

                    $record->markMonthAsPaid($currentMonth);
                    DB::commit();

                    continue;
                }

                // Never pay more than the outstanding balance. Without this a rounding
                // remainder or an edited total could let the final month overshoot.
                $outstanding = round($owed - $alreadyPaid, 2);
                if ($owed > 0 && $monthlyAmount > $outstanding) {
                    Log::info('Monthly payout capped at the outstanding balance', [
                        'record_id' => $record->id,
                        'monthly_amount' => $monthlyAmount,
                        'outstanding' => $outstanding,
                    ]);
                    $monthlyAmount = $outstanding;
                }

                // Idempotent on (teacher, invoice, month, 'schedule.monthly'): if the cron
                // runs twice for the same month — a retry, an overlapping run, or a manual
                // trigger racing the schedule — the second credit is refused by the database.
                $credited = $this->wallet()->credit(
                    $teacher,
                    $monthlyAmount,
                    \App\Models\TeacherWalletEntry::REASON_MONTHLY,
                    $record->id,
                    $currentMonth,
                    $record->invoice_id,
                    null,
                    // See the note on the immediate credit — the subject is part of the key.
                    $record->teacher_subject
                );

                if (! $credited) {
                    // Already paid for this month. Still clear the month so the record does
                    // not keep matching, but do not touch the totals again.
                    $record->markMonthAsPaid($currentMonth);
                    DB::commit();

                    continue;
                }

                // Update total paid to teacher
                $record->increment('total_paid_to_teacher', $monthlyAmount);

                // Mark this month as paid
                $record->markMonthAsPaid($currentMonth);

                // Keep record active even when all months are paid
                // This allows the record to be updated when invoices are modified
                // Records are only deactivated when invoices are deleted
                if ($record->isFullyPaid()) {
                    Log::info('All months paid but keeping record active for potential updates', [
                        'record_id' => $record->id,
                        'teacher_id' => $teacher->id,
                        'selected_months' => $record->selected_months,
                        'months_rest_not_paid_yet' => $record->months_rest_not_paid_yet,
                    ]);
                }

                $processedCount++;
                $totalAmount += $monthlyAmount;

                Log::info('Processed monthly payment for teacher', [
                    'teacher_id' => $teacher->id,
                    'teacher_name' => $teacher->first_name.' '.$teacher->last_name,
                    'amount' => $monthlyAmount,
                    'month' => $currentMonth,
                    'student_name' => $record->student ? $record->student->firstName.' '.$record->student->lastName : 'Unknown',
                ]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error processing monthly payment for teacher', [
                    'teacher_id' => $record->teacher_id,
                    'record_id' => $record->id,
                    'month' => $currentMonth,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Completed monthly teacher payment processing', [
            'month' => $currentMonth,
            'processed_count' => $processedCount,
            'total_amount' => $totalAmount,
        ]);

        return [
            'processed_count' => $processedCount,
            'total_amount' => $totalAmount,
            'month' => $currentMonth,
        ];
    }

    /**
     * Reconcile teacher payouts for months already processed after an invoice change
     * Ensures teacher wallets reflect updated invoice totals by paying the delta
     * IMPROVED: Now handles partial payments for past months correctly
     */
    public function reconcilePaidMonthsForInvoice(Invoice $invoice): array
    {
        $result = [
            'success' => true,
            'adjusted_records' => 0,
            'total_delta' => 0.0,
            'errors' => [],
        ];

        try {
            $records = TeacherMembershipPayment::where('invoice_id', $invoice->id)->get();

            foreach ($records as $record) {
                try {
                    DB::beginTransaction();

                    // Row lock — same reasoning as processMonthlyPayments(): this method
                    // recomputes total_paid_to_teacher and months_rest_not_paid_yet from
                    // values read before the transaction opened.
                    $record = TeacherMembershipPayment::whereKey($record->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $record) {
                        DB::commit();

                        continue;
                    }

                    $selectedMonths = $record->selected_months ?? [];
                    $unpaidMonths = $record->months_rest_not_paid_yet ?? [];

                    $totalMonths = count($selectedMonths);
                    if ($totalMonths === 0) {
                        DB::commit();

                        continue;
                    }

                    // Guard against a zero/absent invoice total: `/` by zero raises
                    // DivisionByZeroError, which extends Error and is NOT caught by the
                    // `catch (\Exception)` blocks below — it would escape as a 500.
                    $invoiceTotal = (float) ($invoice->totalAmount ?? 0);
                    $studentPaymentPercentage = $invoiceTotal > 0
                        ? round(((float) $invoice->amountPaid / $invoiceTotal), 4)
                        : 0.0;

                    $totalTeacherAmount = round((float) ($record->total_teacher_amount ?? 0), 2);

                    // Pay the teacher for the months that have COME ROUND, not the whole
                    // commission at once.
                    //
                    // This used to be a flat `$desiredPaidToDate = $totalTeacherAmount`, so
                    // any edit that moved the paid amount handed over the entire commission
                    // immediately and cleared the month queue. The totals stayed correct, but
                    // the monthly schedule was silently bypassed: a family settling a
                    // three-month plan in September paid the teacher all three months in
                    // September. Multi-month invoices are supposed to release month by month.
                    //
                    // months_rest_not_paid_yet is the queue the cron drains, so the months NOT
                    // in it are exactly the ones already due. One month invoices come out of
                    // this with due == total, which is the immediate payment they always had.
                    $totalMonths = count($selectedMonths);
                    $monthsAlreadyDue = max(0, $totalMonths - count($unpaidMonths));

                    $desiredPaidToDate = $totalMonths > 0
                        ? round($totalTeacherAmount * $monthsAlreadyDue / $totalMonths, 2)
                        : $totalTeacherAmount;

                    // Already paid to teacher (cumulative)
                    $currentPaidToTeacher = round((float) ($record->total_paid_to_teacher ?? 0), 2);

                    $delta = round($desiredPaidToDate - $currentPaidToTeacher, 2);

                    // NOTE: a handlePastMonthPayments() call used to sit here. Its update was
                    // gated on `sort($a) !== sort($b)` — sort() returns a bool, so the test was
                    // `true !== true` and the method never did anything. It has been deleted
                    // rather than repaired: making it work would push PAST months back into
                    // months_rest_not_paid_yet, which the monthly cron would then pay again.

                    if ($delta !== 0.0) {
                        $teacher = Teacher::find($record->teacher_id);
                        if ($teacher) {
                            // Reconciliation is a delta top-up; it can legitimately run more
                            // than once as an invoice is edited, so it carries a per-record
                            // note rather than being deduplicated on the month.
                            $this->wallet()->{$delta > 0 ? 'credit' : 'debit'}(
                                $teacher,
                                abs($delta),
                                \App\Models\TeacherWalletEntry::REASON_RECONCILE,
                                $record->id,
                                null,
                                $invoice->id,
                                'reconcile to '.number_format($desiredPaidToDate, 2)
                            );

                            $newPaidToTeacher = round($currentPaidToTeacher + $delta, 2);

                            $record->update($this->rescheduleRemainingMonths(
                                $newPaidToTeacher,
                                $totalTeacherAmount,
                                $unpaidMonths,
                            ));

                            Log::info('Reconciled teacher payout for updated invoice', [
                                'invoice_id' => $invoice->id,
                                'record_id' => $record->id,
                                'teacher_id' => $record->teacher_id,
                                'selected_months' => $selectedMonths,
                                'unpaid_months' => $unpaidMonths,
                                'student_payment_percentage' => $studentPaymentPercentage,
                                'total_teacher_amount' => $totalTeacherAmount,
                                'desired_paid_to_date' => $desiredPaidToDate,
                                'current_paid_to_teacher' => $currentPaidToTeacher,
                                'delta_applied' => $delta,
                                'improvement_note' => 'Now uses payment percentage instead of month counting',
                            ]);

                            $result['adjusted_records']++;
                            $result['total_delta'] = round($result['total_delta'] + $delta, 2);
                        }
                    } else {
                        // The wallet needs no adjustment, but the SCHEDULE still might: the
                        // invoice total may have changed without moving what is owed to date,
                        // which leaves monthly_teacher_amount describing the old total. And if
                        // the teacher is already paid in full, any residual unpaid months
                        // would be picked up and re-credited by the monthly cron.
                        $record->update($this->rescheduleRemainingMonths(
                            $currentPaidToTeacher,
                            $totalTeacherAmount,
                            $unpaidMonths,
                        ));

                        Log::info('No reconciliation needed (no delta)', [
                            'invoice_id' => $invoice->id,
                            'record_id' => $record->id,
                            'teacher_id' => $record->teacher_id,
                            'desired_paid_to_date' => $desiredPaidToDate,
                            'current_paid_to_teacher' => $currentPaidToTeacher,
                            'student_payment_percentage' => $studentPaymentPercentage,
                        ]);
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $result['errors'][] = $e->getMessage();
                    Log::error('Error reconciling teacher payout for invoice', [
                        'invoice_id' => $invoice->id,
                        'record_id' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Error in reconcilePaidMonthsForInvoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Spread whatever is still owed evenly across the months still queued.
     *
     * Called after reconciliation has decided what the teacher should be holding today. Two
     * jobs, and both of them have been the source of a real payout bug:
     *
     *  - monthly_teacher_amount has to describe the CURRENT remainder. It is computed when
     *    the record is written and never revisited, so after an edit that raised the invoice
     *    total it still described the old one. The cron's outstanding-balance cap stopped
     *    that overpaying, but the months in between were each short, and the whole balance
     *    landed in a lump on the final month.
     *
     *  - once the teacher is paid in full, the queue must be emptied. The cron selects purely
     *    on months_rest_not_paid_yet, so a settled record with months still listed is paid
     *    again — the production invoice 842 case.
     *
     * @param  array<int, string>  $unpaidMonths
     * @return array<string, mixed> attributes for the record update
     */
    private function rescheduleRemainingMonths(float $paidToTeacher, float $totalTeacherAmount, array $unpaidMonths): array
    {
        $update = ['total_paid_to_teacher' => $paidToTeacher];

        $outstanding = round($totalTeacherAmount - $paidToTeacher, 2);
        $remainingCount = count($unpaidMonths);

        if ($totalTeacherAmount > 0 && $outstanding <= 0.01) {
            $update['months_rest_not_paid_yet'] = [];
            $update['monthly_teacher_amount'] = 0;

            return $update;
        }

        if ($remainingCount > 0) {
            $update['monthly_teacher_amount'] = round($outstanding / $remainingCount, 2);
        }

        return $update;
    }

    /**
     * NEW VALIDATION METHOD: Validate invoice payment state before processing
     * Prevents system from entering inconsistent states
     */
    public function validateInvoicePaymentState(Invoice $invoice): array
    {
        $errors = [];
        $warnings = [];

        // Basic validations
        if ($invoice->amountPaid < 0) {
            $errors[] = "Invoice amountPaid cannot be negative: {$invoice->amountPaid}";
        }

        if ($invoice->amountPaid > $invoice->totalAmount) {
            $errors[] = "Invoice amountPaid ({$invoice->amountPaid}) exceeds totalAmount ({$invoice->totalAmount})";
        }

        if ($invoice->rest < 0) {
            $errors[] = "Invoice rest cannot be negative: {$invoice->rest}";
        }

        // Calculate expected rest
        $expectedRest = $invoice->totalAmount - $invoice->amountPaid;
        if (abs($invoice->rest - $expectedRest) > 0.01) {
            $errors[] = "Invoice rest ({$invoice->rest}) doesn't match calculation: {$expectedRest}";
        }

        // Validate payment percentage is reasonable
        $paymentPercentage = $invoice->amountPaid / $invoice->totalAmount;
        if ($paymentPercentage > 1) {
            $errors[] = 'Payment percentage exceeds 100%: '.round($paymentPercentage * 100, 2).'%';
        }

        // Check teacher payment records consistency
        $teacherRecords = TeacherMembershipPayment::where('invoice_id', $invoice->id)->get();
        foreach ($teacherRecords as $record) {
            if ($record->total_paid_to_teacher > $record->total_teacher_amount) {
                $warnings[] = "Teacher {$record->teacher_id} paid amount ({$record->total_paid_to_teacher}) exceeds total amount ({$record->total_teacher_amount})";
            }

            if ($record->total_paid_to_teacher < 0) {
                $errors[] = "Teacher {$record->teacher_id} has negative paid amount: {$record->total_paid_to_teacher}";
            }

            // Check unpaid months logic for past months
            $currentMonth = now()->format('Y-m');
            $selectedMonths = $record->selected_months ?? [];
            $unpaidMonths = $record->months_rest_not_paid_yet ?? [];

            foreach ($selectedMonths as $month) {
                if ($month < $currentMonth && $paymentPercentage < 1.0) {
                    if (! in_array($month, $unpaidMonths)) {
                        $warnings[] = "Past month {$month} is not marked unpaid despite partial payment ({}".round($paymentPercentage * 100, 2).'%)';
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'payment_percentage' => round($paymentPercentage * 100, 2),
            'teacher_records_count' => $teacherRecords->count(),
        ];
    }

    /**
     * NEW VALIDATION METHOD: Validate before processing invoice payments
     * Called automatically before processInvoicePayment
     */
    public function validateBeforeProcessing(Invoice $invoice, array $validated): array
    {
        $errors = [];

        // Validate membership exists and has teachers
        $membership = $invoice->membership;
        if (! $membership) {
            $errors[] = "Invoice {$invoice->id} has no associated membership";

            return ['valid' => false, 'errors' => $errors];
        }

        if (! is_array($membership->teachers) || empty($membership->teachers)) {
            $errors[] = "Aucun enseignant n'est associé à cette adhésion. "
                .'Ouvrez l\'adhésion et ajoutez au moins un enseignant avant de facturer.';
        }

        // Validate offer exists and has valid percentages
        if (! $membership->offer) {
            $errors[] = "Cette adhésion n'a pas d'offre. Sélectionnez une offre sur l'adhésion.";
        } else {
            $offer = $membership->offer;
            if (! is_array($offer->percentage) || $offer->percentage === []) {
                // An EMPTY map is refused as well as a malformed one. It is not a harmless
                // default: the equal-distribution fallback would hand the single teacher the
                // whole 100%, which is a real payout decision nobody made deliberately.
                $errors[] = "L'offre « {$offer->offer_name} » n'a pas de pourcentages valides. "
                    .'Définissez le pourcentage de chaque matière dans l\'offre.';
            } else {
                $percentageSum = round((float) array_sum($offer->percentage), 2);

                if ($percentageSum > 100) {
                    $errors[] = "L'offre « {$offer->offer_name} » répartit {$percentageSum}% entre ses matières "
                        .'(le total ne peut pas dépasser 100%). Corrigez les pourcentages de l\'offre.';
                }

                // Every teacher on this membership must resolve to a real share.
                //
                // This replaces a `count($offer->percentage) != count($membership->teachers)`
                // check, which was wrong in both directions. It refused an offer that listed
                // MORE subjects than the student takes — a perfectly normal configuration —
                // and it accepted an offer whose percentages were fully allocated to someone
                // else, leaving a teacher on 0% with nothing said.
                //
                // Asking the real question instead ("would this teacher be paid nothing?")
                // uses the same resolver as the payout path, so validation and payment can no
                // longer disagree about what a subject is worth.
                if ($percentageSum <= 100 && is_array($membership->teachers)) {
                    foreach ($membership->teachers as $teacherData) {
                        $subject = is_array($teacherData) ? ($teacherData['subject'] ?? null) : null;

                        if ($subject === null || $subject === '') {
                            $errors[] = 'Un enseignant de cette adhésion n\'a pas de matière. '
                                .'Indiquez la matière de chaque enseignant sur l\'adhésion.';

                            continue;
                        }

                        if ($this->resolveTeacherPercentage($offer, $membership, $subject) > 0) {
                            continue;
                        }

                        $teacherName = $this->teacherLabel($teacherData['teacherId'] ?? null);
                        $errors[] = "L'offre « {$offer->offer_name} » ne laisse aucun pourcentage pour "
                            ."{$teacherName} ({$subject}) : cet enseignant serait payé 0 DH. "
                            .'Ajoutez un pourcentage pour cette matière dans l\'offre, '
                            .'ou retirez l\'enseignant de l\'adhésion.';
                    }
                }
            }
        }

        // Validate selected months
        $selectedMonths = $invoice->selected_months ?? [];
        if (is_string($selectedMonths)) {
            $selectedMonths = json_decode($selectedMonths, true) ?? [];
        }

        if (empty($selectedMonths)) {
            $errors[] = 'Cette facture ne couvre aucun mois. Sélectionnez au moins un mois.';
        }

        // Validate amount calculations
        $totalAmountValidation = abs($validated['totalAmount'] - ($validated['amountPaid'] + $validated['rest'])) > 0.01;
        if ($totalAmountValidation) {
            $errors[] = 'Les montants ne s\'additionnent pas : total '
                .number_format((float) $validated['totalAmount'], 2, ',', ' ').' DH ≠ payé '
                .number_format((float) $validated['amountPaid'], 2, ',', ' ').' DH + reste '
                .number_format((float) $validated['rest'], 2, ',', ' ').' DH.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'validated_data' => [
                'selected_months' => $selectedMonths,
                'membership_teachers_count' => is_array($membership->teachers) ? count($membership->teachers) : 0,
                'offer_percentages_valid' => ! empty($errors),
            ],
        ];
    }

    /**
     * Undo the teacher-side effects of an invoice that is being deleted.
     *
     * THE RULE
     * --------
     *   within REVERSAL_DEADLINE_DAYS of the billing date
     *       every dirham credited to the teachers for this invoice is taken back.
     *
     *   after REVERSAL_DEADLINE_DAYS
     *       the teachers KEEP what they were already paid. Nothing is clawed back.
     *
     * In BOTH cases the scheduled months that have not been paid yet are cancelled, because
     * an invoice that no longer exists must never keep generating monthly credits.
     *
     * WHY THIS RETURNS A STRUCTURE
     * ----------------------------
     * "The invoice was deleted but the teacher keeps 240 DH" is a fact the person pressing
     * delete has to be told. This used to return void and write the decision to the log, so
     * the UI reported an unqualified success either way and the money quietly stayed put.
     * The returned array names each teacher, the amount and the reason, and carries
     * ready-to-display French messages.
     *
     * @return array{
     *     reversed: bool,
     *     total_reversed: float,
     *     days_since_billing: int|null,
     *     deadline_days: int,
     *     within_deadline: bool,
     *     applied: array<int, array<string, mixed>>,
     *     blocked: array<int, array<string, mixed>>,
     *     messages: array<int, string>
     * }
     */
    /**
     * What deleting this invoice WOULD do, without doing any of it.
     *
     * Lets the controller ask before it acts: past the deadline the user is shown what the
     * teachers will keep and asked to confirm, instead of finding out afterwards that an
     * irreversible delete has already happened.
     *
     * Shares the deadline arithmetic with reverseInvoicePayments() through
     * reversalDeadlineState(), so the preview and the action can never disagree — a preview
     * that promised one thing and a delete that did another would be worse than no preview.
     *
     * @return array{
     *     reversed: bool, total_reversed: float, days_since_billing: int|null,
     *     deadline_days: int, within_deadline: bool,
     *     applied: array<int, array<string, mixed>>, blocked: array<int, array<string, mixed>>,
     *     messages: array<int, string>
     * }
     */
    public function previewInvoiceReversal(Invoice $invoice): array
    {
        [$daysSinceBilling, $withinDeadline] = $this->reversalDeadlineState($invoice);

        $outcome = $this->emptyReversalOutcome($daysSinceBilling, $withinDeadline);

        foreach ($this->reversibleRecords($invoice) as $record) {
            $teacher = Teacher::find($record->teacher_id);

            if (! $teacher) {
                continue;
            }

            $paid = round((float) ($record->total_paid_to_teacher ?? 0), 2);

            if ($paid <= 0) {
                continue;
            }

            $entry = [
                'record_id' => $record->id,
                'teacher_id' => $teacher->id,
                'teacher_name' => trim($teacher->first_name.' '.$teacher->last_name),
                'subject' => $record->teacher_subject,
                'amount' => $paid,
                'cancelled_months' => array_values($record->months_rest_not_paid_yet ?? []),
            ];

            if (! $withinDeadline) {
                $outcome['blocked'][] = $entry + ['reason' => 'deadline_passed'];

                continue;
            }

            $wallet = round((float) $teacher->wallet, 2);

            if ($wallet <= 0) {
                $outcome['blocked'][] = $entry + ['reason' => 'wallet_empty'];

                continue;
            }

            $recoverable = min($paid, $wallet);
            $outcome['total_reversed'] += $recoverable;
            $outcome['applied'][] = $entry + ['amount' => $recoverable, 'reason' => 'reversed'];

            if ($recoverable < $paid) {
                $outcome['blocked'][] = $entry + [
                    'amount' => round($paid - $recoverable, 2),
                    'reason' => 'wallet_insufficient',
                ];
            }
        }

        $outcome['total_reversed'] = round($outcome['total_reversed'], 2);
        $outcome['reversed'] = $outcome['total_reversed'] > 0;
        $outcome['messages'] = $this->buildReversalMessages($outcome);

        return $outcome;
    }

    /**
     * How long ago this invoice was billed, and whether that is still inside the window.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function reversalDeadlineState(Invoice $invoice): array
    {
        $billingDate = $invoice->billDate;

        // Carbon 3 returns a SIGNED difference, so `now()->diffInDays($past)` is NEGATIVE.
        // That made the old `<= 10` checks always true, which reversed a teacher's entire
        // paid-to-date balance whenever ANY old invoice was deleted. Measuring FORWARD from
        // the billing date gives a positive number for past bills, which is what the rule is
        // stated in. A negative value means a post-dated bill — comfortably inside the
        // window, and handled by the same comparison without a special case.
        $daysSinceBilling = $billingDate
            ? (int) \Carbon\Carbon::parse($billingDate)->startOfDay()->diffInDays(now()->startOfDay(), false)
            : null;

        // No billing date means the deadline cannot be measured. The safe answer is to leave
        // the teachers' money alone rather than guess, so it is treated as expired.
        return [
            $daysSinceBilling,
            $daysSinceBilling !== null && $daysSinceBilling <= self::REVERSAL_DEADLINE_DAYS,
        ];
    }

    /**
     * Deliberately NOT gated on the membership existing, and keyed on invoice_id ALONE.
     *
     * The previous `where('membership_id', ...)` filter meant records whose membership_id had
     * been nulled (the FK is ON DELETE SET NULL) were never found, silently leaving the
     * teacher credited for a deleted invoice.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TeacherMembershipPayment>
     */
    private function reversibleRecords(Invoice $invoice)
    {
        return TeacherMembershipPayment::active()
            ->where('invoice_id', $invoice->id)
            ->get();
    }

    /** @return array<string, mixed> */
    private function emptyReversalOutcome(?int $daysSinceBilling, bool $withinDeadline): array
    {
        return [
            'reversed' => false,
            'total_reversed' => 0.0,
            'days_since_billing' => $daysSinceBilling,
            'deadline_days' => self::REVERSAL_DEADLINE_DAYS,
            'within_deadline' => $withinDeadline,
            'applied' => [],
            'blocked' => [],
            'messages' => [],
        ];
    }

    public function reverseInvoicePayments(Invoice $invoice, ?array $oldData = null): array
    {
        [$daysSinceBilling, $withinDeadline] = $this->reversalDeadlineState($invoice);

        $outcome = $this->emptyReversalOutcome($daysSinceBilling, $withinDeadline);

        foreach ($this->reversibleRecords($invoice) as $record) {
            $this->reverseTeacherPayment($record, $invoice, $withinDeadline, $outcome);
        }

        $outcome['total_reversed'] = round($outcome['total_reversed'], 2);
        $outcome['reversed'] = $outcome['total_reversed'] > 0;
        $outcome['messages'] = $this->buildReversalMessages($outcome);

        Log::info('Invoice reversal completed', [
            'invoice_id' => $invoice->id,
            'bill_date' => $invoice->billDate?->format('Y-m-d'),
            'days_since_billing' => $daysSinceBilling,
            'within_deadline' => $withinDeadline,
            'total_reversed' => $outcome['total_reversed'],
            'blocked_count' => count($outcome['blocked']),
        ]);

        return $outcome;
    }

    /**
     * Turn the outcome into sentences a school secretary can act on.
     *
     * French, because every user-facing string in this application is French.
     */
    private function buildReversalMessages(array $outcome): array
    {
        $messages = [];

        $names = static fn (array $rows) => implode(', ', array_map(
            fn ($r) => trim(($r['teacher_name'] ?? '').' ('.($r['subject'] ?? '—').') : '
                .number_format((float) ($r['amount'] ?? 0), 2, ',', ' ').' DH'),
            $rows
        ));

        $expired = array_values(array_filter($outcome['blocked'], fn ($r) => $r['reason'] === 'deadline_passed'));
        if ($expired !== []) {
            $messages[] = 'Cette facture date de plus de '.$outcome['deadline_days']
                .' jours : le montant déjà versé ne peut plus être retiré du portefeuille des enseignants. '
                .'Concernés — '.$names($expired).'.';
        }

        $short = array_values(array_filter(
            $outcome['blocked'],
            fn ($r) => in_array($r['reason'], ['wallet_empty', 'wallet_insufficient'], true)
        ));
        if ($short !== []) {
            $messages[] = 'Solde insuffisant : le montant n\'a pas pu être repris intégralement, '
                .'le portefeuille aurait été négatif. Restant dû — '.$names($short).'.';
        }

        if ($outcome['total_reversed'] > 0) {
            $messages[] = number_format($outcome['total_reversed'], 2, ',', ' ')
                .' DH ont été repris du portefeuille des enseignants.';
        }

        return $messages;
    }

    /**
     * Apply the deletion rule to ONE teacher-subject payout record.
     *
     * The amount at stake is `total_paid_to_teacher` — the record's own tally of what this
     * invoice actually credited. The old implementation instead re-derived a figure from
     * `total_teacher_amount / months * futureMonths`, which is what the teacher WOULD be owed
     * over the remaining months, not what they were given. Where the cron had not yet paid
     * those months, that debited money the invoice had never credited, and the shortfall came
     * out of credits belonging to other invoices.
     *
     * @param  array<string, mixed>  $outcome  accumulated by reference for the caller's report
     */
    private function reverseTeacherPayment(
        TeacherMembershipPayment $record,
        Invoice $invoice,
        bool $withinDeadline,
        array &$outcome
    ): void {
        try {
            $teacher = Teacher::find($record->teacher_id);
            if (! $teacher) {
                Log::warning('Teacher not found for reversal', ['record_id' => $record->id]);

                return;
            }

            $paidToTeacher = round((float) ($record->total_paid_to_teacher ?? 0), 2);

            $entry = [
                'record_id' => $record->id,
                'teacher_id' => $teacher->id,
                'teacher_name' => trim($teacher->first_name.' '.$teacher->last_name),
                'subject' => $record->teacher_subject,
                'amount' => $paidToTeacher,
                'cancelled_months' => array_values($record->months_rest_not_paid_yet ?? []),
            ];

            if (! $withinDeadline) {
                if ($paidToTeacher > 0) {
                    $outcome['blocked'][] = $entry + ['reason' => 'deadline_passed'];
                }

                $this->stopFutureMonths($record, $invoice);

                return;
            }

            if ($paidToTeacher <= 0) {
                $this->stopFutureMonths($record, $invoice);

                return;
            }

            $walletBefore = round((float) $teacher->wallet, 2);

            if ($walletBefore <= 0) {
                // The teacher has already been paid out in cash. debit() would clamp to zero
                // and silently record nothing; say so instead, because somebody now has to
                // recover this by hand.
                $outcome['blocked'][] = $entry + ['reason' => 'wallet_empty'];
                Log::warning('Reversal blocked: teacher wallet is already at zero', [
                    'record_id' => $record->id,
                    'teacher_id' => $teacher->id,
                    'invoice_id' => $invoice->id,
                    'requested_reversal' => $paidToTeacher,
                ]);

                $this->stopFutureMonths($record, $invoice);

                return;
            }

            $this->wallet()->debit(
                $teacher,
                $paidToTeacher,
                \App\Models\TeacherWalletEntry::REASON_REVERSAL,
                $record->id,
                null,
                $invoice->id,
                'invoice deleted',
                $record->teacher_subject
            );

            // Read the applied amount back rather than assuming it. debit() clamps at zero
            // internally, so what was requested and what moved are not always the same
            // number, and the report must state what actually happened.
            $walletAfter = round((float) $teacher->fresh()->wallet, 2);
            $applied = round($walletBefore - $walletAfter, 2);

            if ($applied > 0) {
                $outcome['total_reversed'] += $applied;
                $outcome['applied'][] = $entry + ['amount' => $applied, 'reason' => 'reversed'];
            }

            $shortfall = round($paidToTeacher - $applied, 2);
            if ($shortfall > 0.001) {
                $outcome['blocked'][] = $entry + ['amount' => $shortfall, 'reason' => 'wallet_insufficient'];
            }

            Log::info('Decremented teacher wallet due to allowed reversal', [
                'record_id' => $record->id,
                'teacher_id' => $teacher->id,
                'invoice_id' => $invoice->id,
                'amount_requested' => $paidToTeacher,
                'amount_applied' => $applied,
                'wallet_before_op' => $walletBefore,
                'wallet_after_op' => $walletAfter,
            ]);

            $this->stopFutureMonths($record, $invoice);

        } catch (\Exception $e) {
            Log::error('Error reversing teacher payment', [
                'record_id' => $record->id,
                'teacher_id' => $record->teacher_id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            // RE-THROW. This used to swallow the exception and "compensate" by flipping
            // is_active to false, which is not a reversal — it just hides the record that
            // would let payouts:audit notice.
            //
            // Callers (InvoiceController::destroy, MembershipController::update) run this
            // inside DB::transaction(). Swallowing meant the invoice delete and membership
            // update COMMITTED while the wallet reversal had silently failed: invoice gone,
            // teacher still holding the money. Propagating rolls the whole unit back, which
            // is the only outcome that keeps the ledger and the invoices agreeing.
            throw $e;
        }
    }

    /**
     * Cancel the payout record's remaining scheduled months.
     *
     * Runs on EVERY deletion path, inside the deadline and outside it. The claw-back is what
     * expires — the cancellation never does. Leaving the months queued on a deleted invoice
     * is how a teacher keeps being credited, month after month, for a bill that no longer
     * exists; the monthly cron selects purely on months_rest_not_paid_yet.
     */
    private function stopFutureMonths(TeacherMembershipPayment $record, Invoice $invoice): void
    {
        $record->update([
            'is_active' => false,
            'months_rest_not_paid_yet' => [],
        ]);

        Log::info('Deactivated teacher membership payment record and cancelled its scheduled months', [
            'record_id' => $record->id,
            'teacher_id' => $record->teacher_id,
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Get teacher earnings summary for a specific period
     */
    public function getTeacherEarningsSummary($teacherId = null, $startMonth = null, $endMonth = null)
    {
        $query = TeacherMembershipPayment::active()
            ->with(['teacher', 'student', 'membership']);

        if ($teacherId) {
            $query->where('teacher_id', $teacherId);
        }

        if ($startMonth) {
            $query->whereJsonContains('selected_months', $startMonth);
        }

        if ($endMonth) {
            $query->whereJsonContains('selected_months', $endMonth);
        }

        return $query->get();
    }

    /**
     * Calculate how much a teacher has been paid for a specific invoice
     */
    public function calculateTeacherPaidAmount(TeacherMembershipPayment $record): array
    {
        $totalSelectedMonths = count($record->selected_months ?? []);
        $remainingUnpaidMonths = count($record->months_rest_not_paid_yet ?? []);
        $monthsPaid = $totalSelectedMonths - $remainingUnpaidMonths;
        $scheduledPaidAmount = round(($monthsPaid * ($record->monthly_teacher_amount ?? 0)), 2);

        // Include immediate amount that was paid
        $immediatePaidAmount = round((float) ($record->immediate_wallet_amount ?? 0), 2);
        $totalPaidAmount = round(($scheduledPaidAmount + $immediatePaidAmount), 2);

        return [
            'total_selected_months' => $totalSelectedMonths,
            'remaining_unpaid_months' => $remainingUnpaidMonths,
            'months_paid' => $monthsPaid,
            'monthly_amount' => round((float) ($record->monthly_teacher_amount ?? 0), 2),
            'scheduled_paid_amount' => $scheduledPaidAmount,
            'immediate_paid_amount' => $immediatePaidAmount,
            'total_paid_amount' => $totalPaidAmount,
            'selected_months' => $record->selected_months,
            'unpaid_months' => $record->months_rest_not_paid_yet,
        ];
    }
}
