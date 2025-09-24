<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== FIXING INVOICE 154 SPECIFICALLY ===\n";
echo "Invoice ID: 154\n";
echo "Student: Mohamed FAIZ\n\n";

// Get invoice details
$invoice = DB::table('invoices')->where('id', 154)->first();
$membership = DB::table('memberships')->where('id', $invoice->membership_id)->first();
$offer = DB::table('offers')->where('id', $membership->offer_id)->first();

echo "Invoice Amount: " . $invoice->amountPaid . " DH\n";
echo "Membership ID: " . $invoice->membership_id . "\n";
echo "Offer ID: " . $membership->offer_id . "\n\n";

// Parse teachers and offer percentages
$teachers = json_decode($membership->teachers, true);
$offerPercentages = json_decode($offer->percentage, true);

echo "Teachers from membership: " . json_encode($teachers) . "\n";
echo "Offer percentages: " . json_encode($offerPercentages) . "\n\n";

// Map teacher IDs to subjects based on offer percentages
$teacherSubjectMap = [];
$subjectIndex = 0;
$subjects = array_keys($offerPercentages);

foreach ($teachers as $teacherData) {
    $teacherId = $teacherData['teacherId'];
    $subject = $subjects[$subjectIndex] ?? 'Math'; // Default to Math if no subject
    $teacherSubjectMap[$teacherId] = $subject;
    $subjectIndex++;
}

echo "Teacher-Subject Mapping:\n";
foreach ($teacherSubjectMap as $teacherId => $subject) {
    echo "Teacher ID {$teacherId} -> Subject: {$subject}\n";
}
echo "\n";

// Calculate selected months from invoice creation date
$selectedMonths = [Carbon::parse($invoice->created_at)->format('Y-m')];
echo "Selected Months: " . json_encode($selectedMonths) . "\n";

// Calculate payment percentage
$paymentPercentage = 0;
if ($invoice->totalAmount > 0) {
    $paymentPercentage = ($invoice->amountPaid / $invoice->totalAmount) * 100;
} else {
    $paymentPercentage = $invoice->amountPaid > 0 ? 100 : 0;
}
$paymentPercentage = min($paymentPercentage, 100);

echo "Payment Percentage: " . $paymentPercentage . "%\n\n";

// Start transaction
DB::beginTransaction();

try {
    $createdRecords = 0;
    
    foreach ($teachers as $teacherData) {
        $teacherId = $teacherData['teacherId'];
        $subject = $teacherSubjectMap[$teacherId];
        $teacherPercentage = $offerPercentages[$subject] ?? 0;
        
        if ($teacherPercentage <= 0) {
            echo "⚠️ Skipping teacher {$teacherId} - no percentage for subject {$subject}\n";
            continue;
        }
        
        // Calculate amounts
        $totalTeacherAmount = ($invoice->amountPaid * $teacherPercentage / 100);
        $monthlyTeacherAmount = $totalTeacherAmount / count($selectedMonths);
        $immediateWalletAmount = $monthlyTeacherAmount;
        
        // For historical invoices, assume all months are paid
        $unpaidMonths = [];
        
        echo "Creating payment record for Teacher {$teacherId} (Subject: {$subject}):\n";
        echo "  - Teacher Percentage: {$teacherPercentage}%\n";
        echo "  - Total Teacher Amount: {$totalTeacherAmount} DH\n";
        echo "  - Monthly Amount: {$monthlyTeacherAmount} DH\n";
        echo "  - Immediate Amount: {$immediateWalletAmount} DH\n";
        
        // Insert teacher payment record
        $paymentRecordId = DB::table('teacher_membership_payments')->insertGetId([
            'student_id' => $invoice->student_id,
            'teacher_id' => $teacherId,
            'membership_id' => $invoice->membership_id,
            'invoice_id' => $invoice->id,
            'selected_months' => json_encode($selectedMonths),
            'months_rest_not_paid_yet' => json_encode($unpaidMonths),
            'total_teacher_amount' => round($totalTeacherAmount, 2),
            'monthly_teacher_amount' => round($monthlyTeacherAmount, 2),
            'payment_percentage' => round($paymentPercentage, 2),
            'teacher_subject' => $subject,
            'teacher_percentage' => round($teacherPercentage, 2),
            'immediate_wallet_amount' => round($immediateWalletAmount, 2),
            'total_paid_to_teacher' => round($immediateWalletAmount, 2),
            'is_active' => true,
            'created_at' => $invoice->created_at,
            'updated_at' => $invoice->created_at,
        ]);
        
        // Update teacher wallet
        DB::table('teachers')
            ->where('id', $teacherId)
            ->increment('wallet', round($immediateWalletAmount, 2));
        
        echo "  ✅ Created payment record ID: {$paymentRecordId}\n";
        echo "  ✅ Updated teacher wallet\n\n";
        
        $createdRecords++;
    }
    
    // Commit transaction
    DB::commit();
    echo "✅ Transaction committed successfully!\n";
    echo "✅ Created {$createdRecords} teacher payment records for invoice 154\n";
    
} catch (Exception $e) {
    // Rollback transaction
    DB::rollback();
    echo "❌ Transaction rolled back due to error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 Invoice 154 should now show 'Actif' status instead of 'En attente'!\n";
echo "💡 Run 'php artisan payments:check-consistency' to verify the fix.\n";
