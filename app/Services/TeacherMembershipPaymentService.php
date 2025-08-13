<?php

namespace App\Services;

use App\Models\TeacherMembershipPayment;
use App\Models\Teacher;
use App\Models\Membership;
use App\Models\Invoice;
use App\Models\Offer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TeacherMembershipPaymentService
{
    /**
     * Create or update teacher membership payment records for an invoice
     */
    public function processInvoicePayment(Invoice $invoice, array $validated)
    {
        $membership = $invoice->membership;
        if (!$membership || !is_array($membership->teachers)) {
            return;
        }

        // Get selected months
        $selectedMonths = $invoice->selected_months ?? [];
        if (is_string($selectedMonths)) {
            $selectedMonths = json_decode($selectedMonths, true) ?? [];
        }
        if (empty($selectedMonths)) {
            // Fallback: if no selected_months, use the billDate month
            $selectedMonths = [$invoice->billDate ? $invoice->billDate->format('Y-m') : null];
        }

        // Calculate the amount that should be included in teacher percentages
        $amountForTeacherPercentage = $validated['totalAmount'];
        if ($validated['includePartialMonth'] && $validated['partialMonthAmount']) {
            $amountForTeacherPercentage -= $validated['partialMonthAmount'];
        }

        // Calculate the percentage of the amount paid (excluding partial month)
        $paymentPercentage = ($amountForTeacherPercentage > 0) ? 
            ($validated['amountPaid'] / $amountForTeacherPercentage) * 100 : 0;

        // Process each teacher
        foreach ($membership->teachers as $teacherData) {
            $this->processTeacherPayment(
                $teacherData,
                $membership,
                $invoice,
                $selectedMonths,
                $paymentPercentage,
                $validated
            );
        }
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
        array $validated
    ) {
        $teacher = Teacher::find($teacherData['teacherId']);
        if (!$teacher) {
            return;
        }

        // Get teacher subject and percentage from offer
        $offer = $membership->offer;
        $teacherSubject = $teacherData['subject'] ?? null;
        
        if (!$offer || !$teacherSubject || !is_array($offer->percentage)) {
            return;
        }

        $teacherPercentage = $offer->percentage[$teacherSubject] ?? 0;
        
        // Calculate teacher's total amount for this payment
        $totalTeacherAmount = ($teacherData['amount'] * $validated['months']) * ($paymentPercentage / 100);
        
        // Calculate monthly amount
        $monthlyTeacherAmount = count($selectedMonths) > 0 ? $totalTeacherAmount / count($selectedMonths) : 0;

        // Check if there's an existing record for this teacher and membership
        $existingRecord = TeacherMembershipPayment::where('teacher_id', $teacher->id)
            ->where('membership_id', $membership->id)
            ->where('is_active', true)
            ->first();

        if ($existingRecord) {
            // Update existing record
            $this->updateExistingRecord($existingRecord, $selectedMonths, $totalTeacherAmount, $monthlyTeacherAmount, $paymentPercentage);
        } else {
            // Create new record
            $this->createNewRecord(
                $teacher,
                $membership,
                $invoice,
                $selectedMonths,
                $totalTeacherAmount,
                $monthlyTeacherAmount,
                $paymentPercentage,
                $teacherSubject,
                $teacherPercentage
            );
        }
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
        float $teacherPercentage
    ) {
        $currentMonth = now()->format('Y-m');
        
        // Check if current month is in selected months
        $isCurrentMonthIncluded = in_array($currentMonth, $selectedMonths);
        
        // If current month is included, increment teacher wallet immediately
        if ($isCurrentMonthIncluded) {
            $teacher->increment('wallet', $monthlyTeacherAmount);
            
            Log::info('Immediately incremented teacher wallet for current month', [
                'teacher_id' => $teacher->id,
                'amount' => $monthlyTeacherAmount,
                'month' => $currentMonth
            ]);
        }
        
        // Remove current month from unpaid months if it was included
        $unpaidMonths = $selectedMonths;
        if ($isCurrentMonthIncluded) {
            $unpaidMonths = array_filter($selectedMonths, function($month) use ($currentMonth) {
                return $month !== $currentMonth;
            });
        }

        TeacherMembershipPayment::create([
            'student_id' => $membership->student_id,
            'teacher_id' => $teacher->id,
            'membership_id' => $membership->id,
            'invoice_id' => $invoice->id,
            'selected_months' => $selectedMonths,
            'months_rest_not_paid_yet' => array_values($unpaidMonths), // Current month removed if included
            'total_teacher_amount' => $totalTeacherAmount,
            'monthly_teacher_amount' => $monthlyTeacherAmount,
            'payment_percentage' => $paymentPercentage,
            'teacher_subject' => $teacherSubject,
            'teacher_percentage' => $teacherPercentage,
            'is_active' => true,
        ]);

        Log::info('Created teacher membership payment record', [
            'teacher_id' => $teacher->id,
            'membership_id' => $membership->id,
            'invoice_id' => $invoice->id,
            'total_amount' => $totalTeacherAmount,
            'monthly_amount' => $monthlyTeacherAmount,
            'selected_months' => $selectedMonths,
            'current_month_paid_immediately' => $isCurrentMonthIncluded
        ]);
    }

    /**
     * Update an existing teacher membership payment record
     */
    private function updateExistingRecord(
        TeacherMembershipPayment $record,
        array $selectedMonths,
        float $totalTeacherAmount,
        float $monthlyTeacherAmount,
        float $paymentPercentage
    ) {
        $currentMonth = now()->format('Y-m');
        
        // Merge new selected months with existing ones
        $allSelectedMonths = array_unique(array_merge($record->selected_months ?? [], $selectedMonths));
        
        // Add new months to unpaid list
        $unpaidMonths = array_unique(array_merge($record->months_rest_not_paid_yet ?? [], $selectedMonths));
        
        // Check if current month is in new selected months and not already paid
        $isCurrentMonthNew = in_array($currentMonth, $selectedMonths) && !in_array($currentMonth, $record->months_rest_not_paid_yet ?? []);
        
        // If current month is new and included, increment teacher wallet immediately
        if ($isCurrentMonthNew) {
            $teacher = Teacher::find($record->teacher_id);
            if ($teacher) {
                $teacher->increment('wallet', $monthlyTeacherAmount);
                
                Log::info('Immediately incremented teacher wallet for current month in update', [
                    'teacher_id' => $teacher->id,
                    'amount' => $monthlyTeacherAmount,
                    'month' => $currentMonth
                ]);
            }
            
            // Remove current month from unpaid months
            $unpaidMonths = array_filter($unpaidMonths, function($month) use ($currentMonth) {
                return $month !== $currentMonth;
            });
        }
        
        // Recalculate total and monthly amounts
        $newTotalAmount = $record->total_teacher_amount + $totalTeacherAmount;
        $newMonthlyAmount = count($allSelectedMonths) > 0 ? $newTotalAmount / count($allSelectedMonths) : 0;

        $record->update([
            'selected_months' => $allSelectedMonths,
            'months_rest_not_paid_yet' => array_values($unpaidMonths),
            'total_teacher_amount' => $newTotalAmount,
            'monthly_teacher_amount' => $newMonthlyAmount,
            'payment_percentage' => $paymentPercentage,
        ]);

        Log::info('Updated teacher membership payment record', [
            'record_id' => $record->id,
            'new_total_amount' => $newTotalAmount,
            'new_monthly_amount' => $newMonthlyAmount,
            'all_selected_months' => $allSelectedMonths,
            'current_month_paid_immediately' => $isCurrentMonthNew
        ]);
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

                // Increment teacher wallet
                $teacher = $record->teacher;
                $teacher->increment('wallet', $record->monthly_teacher_amount);

                // Mark this month as paid
                $record->markMonthAsPaid($currentMonth);

                // If all months are paid, deactivate the record
                if ($record->isFullyPaid()) {
                    $record->update(['is_active' => false]);
                }

                $processedCount++;
                $totalAmount += $record->monthly_teacher_amount;

                Log::info('Processed monthly payment for teacher', [
                    'teacher_id' => $teacher->id,
                    'teacher_name' => $teacher->first_name . ' ' . $teacher->last_name,
                    'amount' => $record->monthly_teacher_amount,
                    'month' => $currentMonth,
                    'student_name' => $record->student ? $record->student->firstName . ' ' . $record->student->lastName : 'Unknown'
                ]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error processing monthly payment for teacher', [
                    'teacher_id' => $record->teacher_id,
                    'record_id' => $record->id,
                    'month' => $currentMonth,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Completed monthly teacher payment processing', [
            'month' => $currentMonth,
            'processed_count' => $processedCount,
            'total_amount' => $totalAmount
        ]);

        return [
            'processed_count' => $processedCount,
            'total_amount' => $totalAmount,
            'month' => $currentMonth
        ];
    }

    /**
     * Reverse teacher payments when an invoice is updated or deleted
     */
    public function reverseInvoicePayments(Invoice $invoice, array $oldData = null)
    {
        $membership = $invoice->membership;
        if (!$membership) {
            return;
        }

        // Find all active records for this membership
        $records = TeacherMembershipPayment::active()
            ->where('membership_id', $membership->id)
            ->where('invoice_id', $invoice->id)
            ->get();

        foreach ($records as $record) {
            $this->reverseTeacherPayment($record, $invoice);
        }
    }

    /**
     * Reverse a specific teacher payment record
     */
    private function reverseTeacherPayment(TeacherMembershipPayment $record, Invoice $invoice)
    {
        $teacher = Teacher::find($record->teacher_id);
        if (!$teacher) {
            return;
        }

        // Calculate how many months were actually paid
        $totalSelectedMonths = count($record->selected_months ?? []);
        $remainingUnpaidMonths = count($record->months_rest_not_paid_yet ?? []);
        $monthsPaid = $totalSelectedMonths - $remainingUnpaidMonths;

        // Calculate total amount to reverse
        $amountToReverse = $monthsPaid * $record->monthly_teacher_amount;

        if ($amountToReverse > 0) {
            // Decrement teacher wallet
            $teacher->decrement('wallet', $amountToReverse);

            Log::info('Reversed teacher payment due to invoice deletion', [
                'record_id' => $record->id,
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->first_name . ' ' . $teacher->last_name,
                'invoice_id' => $invoice->id,
                'total_selected_months' => $totalSelectedMonths,
                'remaining_unpaid_months' => $remainingUnpaidMonths,
                'months_paid' => $monthsPaid,
                'monthly_amount' => $record->monthly_teacher_amount,
                'total_amount_reversed' => $amountToReverse,
                'teacher_wallet_before' => $teacher->wallet + $amountToReverse,
                'teacher_wallet_after' => $teacher->wallet
            ]);
        }

        // Deactivate the record
        $record->update(['is_active' => false]);
        
        Log::info('Deactivated teacher membership payment record', [
            'record_id' => $record->id,
            'teacher_id' => $teacher->id,
            'invoice_id' => $invoice->id
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
        $totalPaidAmount = $monthsPaid * $record->monthly_teacher_amount;

        return [
            'total_selected_months' => $totalSelectedMonths,
            'remaining_unpaid_months' => $remainingUnpaidMonths,
            'months_paid' => $monthsPaid,
            'monthly_amount' => $record->monthly_teacher_amount,
            'total_paid_amount' => $totalPaidAmount,
            'selected_months' => $record->selected_months,
            'unpaid_months' => $record->months_rest_not_paid_yet
        ];
    }
}
