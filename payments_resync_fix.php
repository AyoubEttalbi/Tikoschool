<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

/**
 * payments_resync_fix.php
 *
 * One-off maintenance script to:
 * - Find paid invoices without teacher payment records
 * - For invoices with valid memberships, create missing payment records
 * - Reactivate inactive records and clear unpaid months on fully-paid invoices
 * - Map teacher subjects when membership.teachers entries lack a 'subject'
 * - Use invoice created_at as created/updated timestamps (never current date)
 * - Idempotent: will not double-increment wallets for already existing records
 * - Optional: Create dummy memberships for invoices referencing missing memberships
 *
 * Usage examples:
 *   php payments_resync_fix.php
 *   php payments_resync_fix.php --dry-run
 *   php payments_resync_fix.php --invoice=154
 *   php payments_resync_fix.php --create-dummy-memberships
 *   php payments_resync_fix.php --limit=50
 */

// ---- CLI options ----
$argvOpts = [
    'dry-run' => false,
    'create-dummy-memberships' => false,
    'invoice' => null,
    'limit' => null,
];

foreach ($argv as $arg) {
    if ($arg === '--dry-run') { $argvOpts['dry-run'] = true; }
    if ($arg === '--create-dummy-memberships') { $argvOpts['create-dummy-memberships'] = true; }
    if (str_starts_with($arg, '--invoice=')) { $argvOpts['invoice'] = (int)substr($arg, 10); }
    if (str_starts_with($arg, '--limit=')) { $argvOpts['limit'] = (int)substr($arg, 8); }
}

$DRY_RUN = $argvOpts['dry-run'];
$CREATE_DUMMY = $argvOpts['create-dummy-memberships'];
$FILTER_INVOICE = $argvOpts['invoice'];
$LIMIT = $argvOpts['limit'];

echo "=== PAYMENT RESYNC / FIX ===\n";
if ($DRY_RUN) echo "[DRY-RUN] No changes will be written.\n";
if ($CREATE_DUMMY) echo "[OPTION] Will attempt to create dummy memberships for missing ones.\n";
if ($FILTER_INVOICE) echo "[FILTER] Only invoice ID: {$FILTER_INVOICE}\n";
if ($LIMIT) echo "[LIMIT] Max invoices to process: {$LIMIT}\n";

echo "\n🔍 Gathering candidates (paid invoices without teacher payment records)...\n";

$query = DB::table('invoices as i')
    ->leftJoin('teacher_membership_payments as tmp', 'i.id', '=', 'tmp.invoice_id')
    ->where('i.amountPaid', '>', 0)
    ->whereNull('tmp.invoice_id')
    ->select('i.id', 'i.student_id', 'i.membership_id', 'i.amountPaid', 'i.totalAmount', 'i.created_at', 'i.offer_id');

if ($FILTER_INVOICE) {
    $query->where('i.id', $FILTER_INVOICE);
}
if ($LIMIT) {
    $query->limit($LIMIT);
}

$candidates = $query->orderBy('i.id')->get();

echo "Found " . $candidates->count() . " invoice(s) without teacher payment records.\n";
if ($candidates->count() === 0) {
    echo "✅ Nothing to do.\n";
    exit(0);
}

$validInvoices = [];
$missingMembershipInvoices = [];

foreach ($candidates as $invoice) {
    $membership = DB::table('memberships')->where('id', $invoice->membership_id)->first();
    if ($membership) { $validInvoices[] = $invoice; }
    else { $missingMembershipInvoices[] = $invoice; }
}

echo "\n📊 Breakdown:\n";
echo "- Valid invoices (membership exists): " . count($validInvoices) . "\n";
echo "- Missing membership invoices: " . count($missingMembershipInvoices) . "\n";

$createdPaymentRecords = 0;
$reactivatedRecords = 0;
$clearedUnpaidRecords = 0;
$skipped = 0;
$errors = [];

// Helper: detect offers table columns for dummy creation
function offersTableColumns(): array {
    $cols = DB::select("SHOW COLUMNS FROM offers");
    $names = [];
    foreach ($cols as $col) { $names[] = $col->Field; }
    return $names;
}

// Helper: safe create dummy membership (returns offer_id or null)
function createDummyMembershipForInvoice(object $invoice): ?int {
    $columns = offersTableColumns();

    // Choose or create an offer
    $offerId = null;
    if ($invoice->offer_id) {
        $exists = DB::table('offers')->where('id', $invoice->offer_id)->exists();
        if ($exists) $offerId = $invoice->offer_id;
    }
    if (!$offerId) {
        // Try to reuse any existing offer
        $existing = DB::table('offers')->select('id')->first();
        if ($existing) {
            $offerId = $existing->id;
        } else {
            // Create minimal offer honoring column names
            $data = [ 'percentage' => json_encode(['Math' => 100]), 'created_at' => $invoice->created_at, 'updated_at' => $invoice->created_at ];
            if (in_array('offer_name', $columns)) $data['offer_name'] = 'HISTORICAL_DUMMY_OFFER';
            if (in_array('name', $columns)) $data['name'] = 'HISTORICAL_DUMMY_OFFER';
            if (in_array('description', $columns)) $data['description'] = 'Dummy offer for historical invoice';
            $offerId = DB::table('offers')->insertGetId($data);
        }
    }

    // Create a simple membership referencing that offer
    DB::table('memberships')->insert([
        'student_id' => $invoice->student_id,
        'offer_id' => $offerId,
        'teachers' => json_encode([['teacherId' => 1, 'subject' => 'Math']]),
        'created_at' => $invoice->created_at,
        'updated_at' => $invoice->created_at,
    ]);

    // Note: We cannot insert with a specific ID easily without risking PK conflicts.
    // The invoice still references its original missing membership_id. We will not update invoice's membership_id here to avoid referential risks.
    // Instead, we skip these historical invoices by default unless the user explicitly wants to repair by updating invoice references.

    return $offerId;
}

// Helper: map subject if missing in membership->teachers
function resolveSubjectForTeacher(array $teachers, $teacherId, array $offerPercentages): ?string {
    // If any teacher entry has subject and matches teacherId, return it
    foreach ($teachers as $t) {
        if ((string)($t['teacherId'] ?? '') === (string)$teacherId && !empty($t['subject'])) {
            return $t['subject'];
        }
    }
    // Fallback: map by order of teachers to order of offer percentage keys
    $subjects = array_keys($offerPercentages ?? []);
    $index = array_search($teacherId, array_column($teachers, 'teacherId'));
    if ($index !== false && isset($subjects[$index])) return $subjects[$index];
    return $subjects[0] ?? null;
}

// Helper: ensure invoice's inactive TMP get reactivated and months cleared if fully paid
function reactivateAndCleanForInvoice(int $invoiceId): array {
    $fixed = ['reactivated' => 0, 'cleared_unpaid' => 0];
    $records = DB::table('teacher_membership_payments')->where('invoice_id', $invoiceId)->get();
    foreach ($records as $r) {
        // Reactivate inactive
        if (!$r->is_active) {
            DB::table('teacher_membership_payments')->where('id', $r->id)->update(['is_active' => 1]);
            $fixed['reactivated']++;
        }
        // Clear unpaid months if fully paid
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($invoice && (float)$invoice->amountPaid >= (float)$invoice->totalAmount) {
            DB::table('teacher_membership_payments')->where('id', $r->id)->update(['months_rest_not_paid_yet' => json_encode([])]);
            $fixed['cleared_unpaid']++;
        }
    }
    return $fixed;
}

// Process invoices with valid memberships
if (!empty($validInvoices)) {
    echo "\n🔧 Processing valid invoices...\n";
}

foreach ($validInvoices as $invoice) {
    try {
        $membership = DB::table('memberships')->where('id', $invoice->membership_id)->first();
        $offer = $membership ? DB::table('offers')->where('id', $membership->offer_id)->first() : null;
        $teachers = $membership ? (is_array($membership->teachers) ? $membership->teachers : json_decode($membership->teachers, true)) : null;
        $offerPercentages = $offer ? (is_array($offer->percentage) ? $offer->percentage : json_decode($offer->percentage, true)) : null;

        if (!$membership || !$offer || !is_array($teachers) || !is_array($offerPercentages)) {
            $skipped++;
            echo "⏭️  Invoice {$invoice->id}: Missing membership/offer/teacher data.\n";
            continue;
        }

        // Selected months: derive from invoice created_at
        $selectedMonths = [Carbon::parse($invoice->created_at)->format('Y-m')];

        // Payment percentage
        $paymentPercentage = 0;
        if ((float)$invoice->totalAmount > 0) {
            $paymentPercentage = min(100, round(((float)$invoice->amountPaid / (float)$invoice->totalAmount) * 100, 2));
        } else {
            $paymentPercentage = ((float)$invoice->amountPaid > 0) ? 100 : 0;
        }

        // Process per-invoice in a transaction for idempotence
        if (!$DRY_RUN) DB::beginTransaction();

        foreach ($teachers as $t) {
            $teacherId = $t['teacherId'] ?? null;
            if (!$teacherId) continue;

            // Resolve subject and percentage
            $subject = resolveSubjectForTeacher($teachers, $teacherId, $offerPercentages);
            if (!$subject) continue;
            $teacherPercentage = (float)($offerPercentages[$subject] ?? 0);
            if ($teacherPercentage <= 0) continue;

            // Check if record exists already for (teacher, invoice)
            $existing = DB::table('teacher_membership_payments')
                ->where('teacher_id', $teacherId)
                ->where('invoice_id', $invoice->id)
                ->first();

            // Amounts
            $totalTeacherAmount = round(((float)$invoice->amountPaid * $teacherPercentage / 100), 2);
            $monthlyTeacherAmount = round(($totalTeacherAmount / max(1, count($selectedMonths))), 2);
            $immediateAmount = $monthlyTeacherAmount; // historical immediate

            if ($existing) {
                // Reactivate and clear unpaid if needed; do not double add wallet
                if (!$DRY_RUN) {
                    DB::table('teacher_membership_payments')->where('id', $existing->id)->update([
                        'is_active' => 1,
                        'months_rest_not_paid_yet' => json_encode([]),
                        'selected_months' => json_encode($selectedMonths),
                        'total_teacher_amount' => $totalTeacherAmount,
                        'monthly_teacher_amount' => $monthlyTeacherAmount,
                        'payment_percentage' => $paymentPercentage,
                        'teacher_subject' => $subject,
                        'teacher_percentage' => round($teacherPercentage, 2),
                    ]);
                }
            } else {
                if (!$DRY_RUN) {
                    $newId = DB::table('teacher_membership_payments')->insertGetId([
                        'student_id' => $invoice->student_id,
                        'teacher_id' => $teacherId,
                        'membership_id' => $invoice->membership_id,
                        'invoice_id' => $invoice->id,
                        'selected_months' => json_encode($selectedMonths),
                        'months_rest_not_paid_yet' => json_encode([]),
                        'total_teacher_amount' => $totalTeacherAmount,
                        'monthly_teacher_amount' => $monthlyTeacherAmount,
                        'payment_percentage' => $paymentPercentage,
                        'teacher_subject' => $subject,
                        'teacher_percentage' => round($teacherPercentage, 2),
                        'immediate_wallet_amount' => $immediateAmount,
                        'total_paid_to_teacher' => $immediateAmount,
                        'is_active' => 1,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->created_at,
                    ]);
                    // Increment wallet only for creation
                    DB::table('teachers')->where('id', $teacherId)->increment('wallet', $immediateAmount);
                    $createdPaymentRecords++;
                } else {
                    echo "[DRY] Would create TMP for teacher {$teacherId} / invoice {$invoice->id} ({$subject})\n";
                }
            }
        }

        // Reactivate/clear unpaid months for invoice records
        if (!$DRY_RUN) {
            $fix = reactivateAndCleanForInvoice($invoice->id);
            $reactivatedRecords += $fix['reactivated'];
            $clearedUnpaidRecords += $fix['cleared_unpaid'];
            DB::commit();
        }

        echo "✅ Invoice {$invoice->id}: processed\n";

    } catch (Exception $e) {
        if (!$DRY_RUN) {
            try { DB::rollBack(); } catch (Exception $ignored) {}
        }
        $errors[] = "Invoice {$invoice->id}: " . $e->getMessage();
        echo "❌ Invoice {$invoice->id}: " . $e->getMessage() . "\n";
    }
}

// Process missing memberships (optional)
if (!empty($missingMembershipInvoices)) {
    echo "\n⚠️  Invoices with missing memberships: " . count($missingMembershipInvoices) . "\n";
    if ($CREATE_DUMMY) {
        echo "🔧 Attempting to create dummy memberships for missing ones...\n";
        foreach ($missingMembershipInvoices as $invoice) {
            try {
                if ($DRY_RUN) {
                    echo "[DRY] Would create dummy membership for invoice {$invoice->id}\n";
                    continue;
                }
                createDummyMembershipForInvoice($invoice);
                echo "✅ Dummy membership created for invoice {$invoice->id}.\n";
            } catch (Exception $e) {
                $errors[] = "Dummy membership for invoice {$invoice->id}: " . $e->getMessage();
                echo "❌ Dummy membership for invoice {$invoice->id}: " . $e->getMessage() . "\n";
            }
        }
        echo "Note: invoices still reference their original missing membership IDs; updating invoice references is intentionally not done to avoid data drift.\n";
    } else {
        echo "(Skipped creating dummy memberships. Run with --create-dummy-memberships if you want that.)\n";
    }
}

// Summary
echo "\n" . str_repeat('=', 70) . "\n";
echo "📊 SUMMARY\n";
echo str_repeat('=', 70) . "\n";
echo "Created payment records: {$createdPaymentRecords}\n";
echo "Reactivated records: {$reactivatedRecords}\n";
echo "Cleared unpaid months: {$clearedUnpaidRecords}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: " . count($errors) . "\n";
if (!empty($errors)) {
    foreach (array_slice($errors, 0, 10) as $err) echo "  - {$err}\n";
    if (count($errors) > 10) echo "  ... and " . (count($errors) - 10) . " more errors\n";
}

echo "\n✅ Done. Suggested next: php artisan payments:check-consistency\n";
