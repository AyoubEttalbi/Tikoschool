<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use Illuminate\Support\Collection;

/**
 * The teacher's earnings-per-month pipeline — THE canonical source.
 *
 * One membership can span several months and one invoice can cover several
 * months: this walks every non-deleted invoice of every membership where the
 * teacher appears (memberships.teachers JSON), computes their offer percentage
 * share of amountPaid, honours partial-month allocation, and emits ONE ROW PER
 * MONTH. Consumers:
 *
 *  - TeacherController::show   (« Gains Mensuels » table on the profile)
 *  - MyPaymentsController      (« Mes gains » table on the payroll page)
 *
 * Keep every consumer on THIS method: a change to the share math made here is
 * a change everywhere, which is the whole point.
 */
class TeacherEarnings
{
    public static function monthlyRows(Teacher $teacher): Collection
    {
        // Deleted memberships stay included on purpose: past months a teacher
        // earned on a since-withdrawn student must still show (flagged).
        $memberships = Membership::withTrashed()
            ->whereJsonContains('teachers', [['teacherId' => (string) $teacher->id]])
            ->with(['invoices' => function ($query) {
                // Only include non-deleted invoices
                $query->whereNull('deleted_at');
            }, 'student', 'student.school', 'student.class', 'offer'])
            ->get();

        return $memberships->flatMap(function ($membership) use ($teacher) {
            if (! $membership->student) {
                return [];
            }

            return $membership->invoices->flatMap(function ($invoice) use ($membership, $teacher) {
                // Find the teacher's entry in the membership JSON — it carries
                // the subject this commission belongs to.
                $teacherData = collect($membership->teachers)->first(function ($item) use ($teacher) {
                    return isset($item['teacherId']) && $item['teacherId'] == (string) $teacher->id;
                });

                if (! $teacherData) {
                    return [];
                }

                $subject = $teacherData['subject'] ?? ($teacher->subjects->first()->name ?? 'Unknown');

                $selectedMonths = $invoice->selected_months ?? [];
                if (is_string($selectedMonths)) {
                    $selectedMonths = json_decode($selectedMonths, true) ?? [];
                }

                $billMonth = $invoice->billDate
                    ? ($invoice->billDate instanceof \Carbon\Carbon ? $invoice->billDate->format('Y-m') : date('Y-m', strtotime($invoice->billDate)))
                    : null;
                if (! $billMonth) {
                    $billMonth = $invoice->created_at ? date('Y-m', strtotime($invoice->created_at)) : null;
                }

                if (empty($selectedMonths)) {
                    $selectedMonths = [$billMonth];
                }

                $includePartialMonth = $invoice->includePartialMonth ?? false;
                $partialMonthAmount = $invoice->partialMonthAmount ?? 0;
                if ($includePartialMonth && $partialMonthAmount > 0 && $billMonth && ! in_array($billMonth, $selectedMonths)) {
                    array_unshift($selectedMonths, $billMonth);
                }

                $schoolName = 'Unknown';
                $schoolId = null;
                if ($membership->student->school) {
                    $schoolName = $membership->student->school->name;
                    $schoolId = $membership->student->school->id;
                } else {
                    $schoolId = $membership->student->schoolId;
                    $school = \App\Models\School::find($schoolId);
                    if ($school) {
                        $schoolName = $school->name;
                    }
                }
                $className = $membership->student->class ? $membership->student->class->name : 'Unknown';

                // Percentage from the offer; 0 keeps the row visible rather than
                // dropping money the wallet actually paid. @see \App\Support\OfferPercentages
                $teacherPercentage = OfferPercentages::forSubject($invoice->offer, $subject) ?? 0;

                $totalTeacherAmount = $invoice->amountPaid * ($teacherPercentage / 100);

                $teacherAmountForPartial = 0;
                $fullMonthsAmount = 0;
                if ($includePartialMonth && $partialMonthAmount > 0) {
                    $teacherAmountForPartial = $partialMonthAmount * ($teacherPercentage / 100);
                    $countFullMonths = count(array_filter($selectedMonths, fn ($m) => $m !== $billMonth));
                    $remainingTeacherAmount = $totalTeacherAmount - $teacherAmountForPartial;
                    if ($remainingTeacherAmount < 0) {
                        $remainingTeacherAmount = max(0, $totalTeacherAmount);
                    }
                    $fullMonthsAmount = $countFullMonths > 0 ? $remainingTeacherAmount / $countFullMonths : 0;
                } else {
                    $countFullMonths = count($selectedMonths);
                    $fullMonthsAmount = $countFullMonths > 0 ? ($totalTeacherAmount / $countFullMonths) : 0;
                }

                $monthlyInvoices = [];
                foreach ($selectedMonths as $month) {
                    if (! $month) {
                        continue;
                    }

                    $monthDisplay = date('m-Y', strtotime($month.'-01'));

                    // Paid-state per month comes from the payout tracker.
                    $teacherPayment = TeacherMembershipPayment::where('teacher_id', $teacher->id)
                        ->where('membership_id', $membership->id)
                        ->where('invoice_id', $invoice->id)
                        ->whereJsonContains('selected_months', $month)
                        ->first();

                    $isMonthPaid = false;
                    if ($teacherPayment) {
                        $isMonthPaid = ! in_array($month, $teacherPayment->months_rest_not_paid_yet ?? []);
                    }

                    $amountForThisMonth = ($includePartialMonth && $partialMonthAmount > 0 && $month === $billMonth)
                        ? $teacherAmountForPartial
                        : $fullMonthsAmount;

                    $monthlyInvoices[] = [
                        'id' => $invoice->id.'_'.$month,
                        'invoice_id' => $invoice->id,
                        'membership_id' => $invoice->membership_id,
                        'student_id' => $invoice->student_id,
                        'student_name' => $membership->student->firstName.' '.$membership->student->lastName,
                        'student_class' => $className,
                        'student_school' => $schoolName,
                        'schoolId' => $schoolId,
                        'billDate' => $month.'-01',
                        'invoiceBillDate' => $invoice->billDate ? ($invoice->billDate instanceof \Carbon\Carbon ? $invoice->billDate->format('Y-m-d') : date('Y-m-d', strtotime($invoice->billDate))) : null,
                        'month_display' => $monthDisplay,
                        'months' => $invoice->months,
                        'creationDate' => $invoice->creationDate,
                        'created_at' => $invoice->created_at,
                        'totalAmount' => $invoice->totalAmount,
                        'amountPaid' => $invoice->amountPaid,
                        'rest' => $invoice->rest,
                        'offer_id' => $invoice->offer_id,
                        'offer_name' => $invoice->offer ? $invoice->offer->offer_name : null,
                        'endDate' => $invoice->endDate,
                        'includePartialMonth' => $invoice->includePartialMonth,
                        'partialMonthAmount' => $invoice->partialMonthAmount,
                        'teacher_amount' => $amountForThisMonth,
                        'months_count' => 1,
                        'total_months' => count($selectedMonths),
                        'membership_deleted' => ! is_null($membership->deleted_at),
                        'membership_deleted_at' => $membership->deleted_at,
                        'is_month_paid' => $isMonthPaid,
                    ];
                }

                return $monthlyInvoices;
            });
        });
    }
}
