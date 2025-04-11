<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
class TransactionController extends Controller
{
/**
 * Common data needed for most views
 *
 * @return array
 */
private function getCommonData()
{
    // Get all users with their id, name, email, and role
    $users = User::with(['teacher', 'assistant'])->get();
    
    // Fetch wallet value for teachers and salary for assistants
    $usersWithDetails = $users->map(function ($user) {
        if ($user->role === 'teacher' && $user->teacher) {
            $user->wallet = $user->teacher->wallet ?? 0;
        } elseif ($user->role === 'assistant' && $user->assistant) {
            $user->salary = $user->assistant->salary ?? 0;
        }
        return $user;
    });
    
    // Count of employees by role
    $teacherCount = $users->where('role', 'teacher')->count();
    $assistantCount = $users->where('role', 'assistant')->count();
    
    // Total of wallets and salaries
    $totalWallet = $usersWithDetails->where('role', 'teacher')->sum('wallet') ?? 0;
    $totalSalary = $usersWithDetails->where('role', 'assistant')->sum('salary') ?? 0;
    
    // Get transactions with their associated user
    $transactions = Transaction::with('user')
        ->orderBy('payment_date', 'desc')
        ->simplePaginate(20000);
    
    // Calculate admin earnings for the current and previous year
    $adminEarnings = $this->calculateAdminEarningsForComparison();
    
    // Get all available years for the filter dropdown
    $availableYears = $this->getAvailableYears();
    
    return [
        'users' => $usersWithDetails,
        'transactions' => $transactions,
        'teacherCount' => $teacherCount,
        'assistantCount' => $assistantCount,
        'totalWallet' => $totalWallet,
        'totalSalary' => $totalSalary,
        'adminEarnings' => $adminEarnings,
        'availableYears' => $availableYears
    ];
}


/**
 * Get all available years from both invoices and transactions data
 *
 * @return array
 */
private function getAvailableYears()
{
    // Get years from invoices table
    $invoiceYears = DB::table('invoices')
        ->select(DB::raw('DISTINCT EXTRACT(YEAR FROM "billDate") as year'))
        ->whereNull('deleted_at')
        ->pluck('year')
        ->toArray();
    
    // Get years from transactions table
    $transactionYears = DB::table('transactions')
        ->select(DB::raw('DISTINCT EXTRACT(YEAR FROM payment_date) as year'))
        ->pluck('year')
        ->toArray();
    
    // Merge all unique years
    $years = array_unique(array_merge($invoiceYears, $transactionYears));
    
    // Sort in descending order
    rsort($years);
    
    // If no years found, include at least the current year
    if (empty($years)) {
        $years = [now()->year];
    }
    
    return $years;
}

/**
 * Calculate admin earnings based on invoices, grouped by month
 *
 * @return array
 */
private function calculateAdminEarningsPerMonth()
{
    // Get current year
    $currentYear = now()->year;
    
    // Get invoices from last 12 months
    $startDate = now()->subMonths(11)->startOfMonth();
    
    // Get all paid amounts from invoices, grouped by month
    $monthlyEarnings = DB::table('invoices')
        ->select(
            DB::raw('EXTRACT(YEAR FROM "billDate") as year'),
            DB::raw('EXTRACT(MONTH FROM "billDate") as month'),
            DB::raw('SUM("amountPaid") as totalPaid')
        )
        ->whereNull('deleted_at')
        ->where('billDate', '>=', $startDate)
        ->groupBy('year', 'month')
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->get();
    
    // Initialize array for all months (including months with zero earnings)
    $allMonths = [];
    for ($i = 0; $i < 12; $i++) {
        $date = now()->subMonths($i);
        $yearMonth = $date->format('Y-m');
        $allMonths[$yearMonth] = [
            'year' => $date->year,
            'month' => $date->month,
            'monthName' => $date->format('F'),
            'totalPaid' => 0
        ];
    }
    
    // Fill in actual earnings data
    foreach ($monthlyEarnings as $earning) {
        $yearMonth = $earning->year . '-' . sprintf('%02d', $earning->month);
        if (isset($allMonths[$yearMonth])) {
            $allMonths[$yearMonth]['totalPaid'] = $earning->totalPaid ?? 0;
        }
    }
    
    // Calculate additional metrics for each month
    $processedEarnings = [];
    foreach ($allMonths as $yearMonth => $data) {
        // Get total expenses for this month (teacher wallets + assistant salaries)
        $monthDate = Carbon::createFromDate($data['year'], $data['month'], 1);
        
        // Add revenue from course enrollments
        $monthlyEnrollmentRevenue = DB::table('enrollments')
            ->join('courses', 'enrollments.course_id', '=', 'courses.id')
            ->whereRaw('EXTRACT(YEAR FROM enrollments.created_at) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM enrollments.created_at) = ?', [$data['month']])
            ->whereNull('enrollments.deleted_at')
            ->sum('courses.price');
        
        $monthlyExpenses = DB::table('transactions')
            ->where(function ($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment')
                      ->orWhere('type', 'expense');
            })
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$data['month']])
            ->sum('amount');
        
        // Calculate total revenue (invoices + enrollments)
        $totalRevenue = ($data['totalPaid'] ?? 0) + ($monthlyEnrollmentRevenue ?? 0);
        
        // Calculate profit
        $profit = $totalRevenue - $monthlyExpenses;
        
        $processedEarnings[] = [
            'year' => $data['year'],
            'month' => $data['month'],
            'monthName' => $data['monthName'],
            'totalRevenue' => $totalRevenue, // Updated to include both invoice payments and course enrollments
            'totalExpenses' => $monthlyExpenses,
            'profit' => $profit,
            'yearMonth' => $yearMonth  // This is fine to keep but not required by the frontend
        ];
    }
    
    // Sort by year and month (descending)
    usort($processedEarnings, function ($a, $b) {
        if ($a['year'] != $b['year']) {
            return $b['year'] <=> $a['year']; // Latest year first
        }
        return $b['month'] <=> $a['month']; // Latest month first
    });
    
    return $processedEarnings;
}

/**
 * Get admin earnings data for the dashboard
 *
 * @return \Illuminate\Http\JsonResponse
 */
public function getAdminEarningsDashboard()
{
    // Get all available years from the database
    $availableYears = $this->getAvailableYears();
    
    // Get earliest year with data
    $earliestYear = end($availableYears);
    reset($availableYears);
    
    // If no earliest year found, default to current year - 1
    if (!$earliestYear) {
        $earliestYear = now()->year - 1;
    }
    
    // Set start date to the beginning of the earliest year
    $startDate = Carbon::createFromDate($earliestYear, 1, 1)->format('Y-m-d');
    
    // Get all paid amounts from invoices, grouped by month
    $monthlyEarnings = DB::table('invoices')
        ->select(
            DB::raw('EXTRACT(YEAR FROM "billDate") as year'),
            DB::raw('EXTRACT(MONTH FROM "billDate") as month'),
            DB::raw('SUM("amountPaid") as totalPaid')
        )
        ->whereNull('deleted_at')
        ->where('billDate', '>=', $startDate)
        ->groupBy('year', 'month')
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->get();
    
    // Initialize array for all months in the period
    $allMonths = [];
    
    // Create entries for each month from earliest year to now
    $endDate = now();
    $date = Carbon::parse($startDate);
    
    while ($date->lte($endDate)) {
        $yearMonth = $date->format('Y-m');
        $allMonths[$yearMonth] = [
            'year' => $date->year,
            'month' => $date->month,
            'monthName' => $date->format('F'),
            'totalPaid' => 0
        ];
        $date->addMonth();
    }
    
    // Fill in actual earnings data
    foreach ($monthlyEarnings as $earning) {
        $yearMonth = $earning->year . '-' . sprintf('%02d', $earning->month);
        if (isset($allMonths[$yearMonth])) {
            $allMonths[$yearMonth]['totalPaid'] = $earning->totalPaid ?? 0;
        }
    }
    
    // Calculate additional metrics for each month
    $processedEarnings = [];
    foreach ($allMonths as $yearMonth => $data) {
        // Get total expenses for this month (teacher wallets + assistant salaries + expenses)
        $monthDate = Carbon::createFromDate($data['year'], $data['month'], 1);
        
        // Add revenue from course enrollments
        $monthlyEnrollmentRevenue = DB::table('enrollments')
            ->join('courses', 'enrollments.course_id', '=', 'courses.id')
            ->whereRaw('EXTRACT(YEAR FROM enrollments.created_at) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM enrollments.created_at) = ?', [$data['month']])
            ->whereNull('enrollments.deleted_at')
            ->sum('courses.price');
        
        $monthlyExpenses = DB::table('transactions')
            ->where(function ($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment')
                      ->orWhere('type', 'expense');
            })
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$data['month']])
            ->sum('amount');
        
        // Calculate total revenue (invoices + enrollments)
        $totalRevenue = ($data['totalPaid'] ?? 0) + ($monthlyEnrollmentRevenue ?? 0);
        
        // Calculate profit
        $profit = $totalRevenue - $monthlyExpenses;
        
        $processedEarnings[] = [
            'year' => $data['year'],
            'month' => $data['month'],
            'monthName' => $data['monthName'],
            'totalRevenue' => $totalRevenue, // Updated to include both invoice payments and course enrollments
            'totalExpenses' => $monthlyExpenses,
            'profit' => $profit,
            'yearMonth' => $yearMonth  // This is fine to keep but not required by the frontend
        ];
    }
    
    // Include available years in the response
    return response()->json([
        'earnings' => $processedEarnings,
        'availableYears' => $availableYears
    ]);
}

/**
 * Calculate admin earnings for comparison between years
 *
 * @return array
 */
private function calculateAdminEarningsForComparison()
{
    // Get all available years from the database
    $availableYears = $this->getAvailableYears();
    
    // Get earliest year with data
    $earliestYear = end($availableYears);
    reset($availableYears);
    
    // If no earliest year found, default to current year - 1
    if (!$earliestYear) {
        $earliestYear = now()->year - 1;
    }
    
    // Set start date to the beginning of the earliest year
    $startDate = Carbon::createFromDate($earliestYear, 1, 1);
    
    // Get all paid amounts from invoices, grouped by month and year
    $monthlyEarnings = DB::table('invoices')
        ->select(
            DB::raw('EXTRACT(YEAR FROM "billDate") as year'),
            DB::raw('EXTRACT(MONTH FROM "billDate") as month'),
            DB::raw('SUM("amountPaid") as totalPaid')
        )
        ->whereNull('deleted_at')
        ->where('billDate', '>=', $startDate)
        ->groupBy('year', 'month')
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->get();
    
    // Calculate the number of months to include in the analysis
    $now = now();
    $monthDiff = ($now->year - $earliestYear) * 12 + $now->month;
    
    // Initialize array for all months from the earliest year to now
    $allMonths = [];
    for ($i = 0; $i < $monthDiff; $i++) {
        $date = now()->subMonths($i);
        $yearMonth = $date->format('Y-m');
        $allMonths[$yearMonth] = [
            'year' => $date->year,
            'month' => $date->month,
            'monthName' => $date->format('F'),
            'totalPaid' => 0
        ];
    }
    
    // Fill in actual earnings data
    foreach ($monthlyEarnings as $earning) {
        $yearMonth = $earning->year . '-' . sprintf('%02d', $earning->month);
        if (isset($allMonths[$yearMonth])) {
            $allMonths[$yearMonth]['totalPaid'] = $earning->totalPaid ?? 0;
        }
    }
    
    // Calculate additional metrics for each month
    $processedEarnings = [];
    foreach ($allMonths as $yearMonth => $data) {
        // Get total expenses for this month (teacher wallets + assistant salaries + expenses)
        $monthDate = Carbon::createFromDate($data['year'], $data['month'], 1);
        
        // Add revenue from course enrollments
        $monthlyEnrollmentRevenue = DB::table('enrollments')
            ->join('courses', 'enrollments.course_id', '=', 'courses.id')
            ->whereRaw('EXTRACT(YEAR FROM enrollments.created_at) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM enrollments.created_at) = ?', [$data['month']])
            ->whereNull('enrollments.deleted_at')
            ->sum('courses.price');
            
        // Get existing monthly expenses
        $monthlyExpenses = DB::table('transactions')
            ->where(function ($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment')
                      ->orWhere('type', 'expense');
            })
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$data['year']])
            ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$data['month']])
            ->sum('amount');
        
        // Calculate total revenue (invoices + enrollments)
        $totalRevenue = ($data['totalPaid'] ?? 0) + ($monthlyEnrollmentRevenue ?? 0);
        
        // Calculate profit
        $profit = $totalRevenue - $monthlyExpenses;
        
        $processedEarnings[] = [
            'year' => $data['year'],
            'month' => $data['month'],
            'monthName' => $data['monthName'],
            'totalRevenue' => $totalRevenue,  // Updated to include both payment sources
            'totalExpenses' => $monthlyExpenses,
            'profit' => $profit,
            'yearMonth' => $yearMonth  // This is fine to keep but not required by the frontend
        ];
    }
    
    // Sort by year and month (descending)
    usort($processedEarnings, function ($a, $b) {
        if ($a['year'] != $b['year']) {
            return $b['year'] <=> $a['year']; // Latest year first
        }
        return $b['month'] <=> $a['month']; // Latest month first
    });
    
    return [
        'earnings' => $processedEarnings,
        'availableYears' => $availableYears
    ];
}

/**
 * Display a listing of transactions.
 *
 * @return \Illuminate\Http\Response
 */
public function index()
{
    $data = $this->getCommonData();
    $data['formType'] = null;
    $data['transaction'] = null;
    
    return Inertia::render('Menu/PaymentsPage', $data);
}

    /**
     * Show the form for creating a new transaction.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $data = $this->getCommonData();
        $data['formType'] = 'create';
        $data['transaction'] = null;
        
        return Inertia::render('Menu/PaymentsPage', $data);
    }

    /**
     * Store a newly created transaction in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validated = $this->validateTransactionData($request);
        
        // Get the user to check their role
        $user = null;
        if (!empty($validated['user_id'])) {
            $user = User::find($validated['user_id']);
        }
        
        // Check if this is a payment for a teacher and validate against wallet
        if ($user && $user->role === 'teacher' && $validated['type'] === 'payment') {
            // Get teacher's wallet
            $teacher = $user->teacher;
            if ($teacher && $validated['amount'] > $teacher->wallet) {
                return redirect()->back()
                    ->withErrors(['amount' => 'Payment amount cannot exceed the teacher\'s wallet balance.'])
                    ->withInput();
            }
            
            // Auto-append to description if not already mentioned
            if (!str_contains(strtolower($validated['description'] ?? ''), 'wallet payment')) {
                $validated['description'] = ($validated['description'] ? $validated['description'] . ' - ' : '') . 
                    'Wallet payment for teacher ' . $user->name;
            }
        }
        
        $transaction = Transaction::create($validated);
        
        if (in_array($transaction->type, ['salary', 'wallet', 'payment']) && $transaction->user_id) {
            $this->updateEmployeeBalance($transaction);
        }
        
        return redirect()->route('transactions.index')
            ->with('success', 'Transaction created successfully!');
    }

    /**
     * Display the specified transaction.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = $this->getCommonData();
        $data['formType'] = null;
        $data['transaction'] = Transaction::with('user')->findOrFail($id);
        
        return Inertia::render('Menu/PaymentsPage', $data);
    }

    /**
     * Show the form for editing the specified transaction.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $data = $this->getCommonData();
        $data['formType'] = 'edit';
        $data['transaction'] = Transaction::with('user')->findOrFail($id);
        
        return Inertia::render('Menu/PaymentsPage', $data);
    }

    /**
     * Update the specified transaction in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validated = $this->validateTransactionData($request);
        $transaction = Transaction::findOrFail($id);
        
        // Store old values for comparison
        $oldType = $transaction->type;
        $oldAmount = $transaction->amount;
        $oldUserId = $transaction->user_id;
        
        // Get the user to check their role
        $user = null;
        if (!empty($validated['user_id'])) {
            $user = User::find($validated['user_id']);
        }
        
        // Check if this is a payment for a teacher and validate against wallet
        if ($user && $user->role === 'teacher' && $validated['type'] === 'payment') {
            // Get teacher's wallet
            $teacher = $user->teacher;
            
            // Only check the wallet balance if the amount is increasing or this is a new payment
            $isNewPayment = $oldType !== 'payment' || $oldUserId !== $validated['user_id'];
            $amountIncreased = $oldType === 'payment' && $oldUserId === $validated['user_id'] && $validated['amount'] > $oldAmount;
            
            if ($teacher && ($isNewPayment || $amountIncreased)) {
                $additionalAmount = $isNewPayment ? $validated['amount'] : ($validated['amount'] - $oldAmount);
                
                if ($additionalAmount > $teacher->wallet) {
                    return redirect()->back()
                        ->withErrors(['amount' => 'Payment amount cannot exceed the teacher\'s wallet balance.'])
                        ->withInput();
                }
            }
            
            // Auto-append to description if not already mentioned
            if (!str_contains(strtolower($validated['description'] ?? ''), 'wallet payment')) {
                $validated['description'] = ($validated['description'] ? $validated['description'] . ' - ' : '') . 
                    'Wallet payment for teacher ' . $user->name;
            }
        }
        
        // Update the transaction
        $transaction->update($validated);

        // If payment type or amount changed, adjust employee balance
        if (($oldType !== $validated['type'] || $oldAmount !== $validated['amount'] || $oldUserId !== $validated['user_id']) 
            && in_array($validated['type'], ['salary', 'wallet', 'payment'])) {
            
            // Revert old transaction effect if necessary
            if (in_array($oldType, ['salary', 'wallet', 'payment'])) {
                $this->revertEmployeeBalance([
                    'type' => $oldType,
                    'user_id' => $oldUserId,
                    'amount' => $oldAmount
                ]);
            }
            
            // Apply new transaction effect
            $this->updateEmployeeBalance($transaction);
        }

        return redirect()->route('transactions.index')->with('success', 'Transaction updated successfully!');
    }

    /**
     * Remove the specified transaction from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $transaction = Transaction::findOrFail($id);
        
        // Revert the effect of the transaction on employee balance
        if (in_array($transaction->type, ['salary', 'wallet', 'payment'])) {
            $this->revertEmployeeBalance($transaction);
        }
        
        $transaction->delete();
        return redirect()->route('transactions.index')->with('success', 'Transaction deleted successfully!');
    }

    /**
     * Display transactions for a specific employee.
     *
     * @param  int  $employeeId
     * @return \Illuminate\Http\Response
     */
    public function employeeTransactions($employeeId)
    {
        // Fetch the employee's transactions
        $transactions = Transaction::with('user')
            ->where('user_id', $employeeId)
            ->orderBy('payment_date', 'desc')
            ->get();

        // Fetch the employee details
        $employee = User::findOrFail($employeeId);

        return Inertia::render('Payments/EmployeePaymentHistory', [
            'transactions' => $transactions,
            'employee' => $employee,
        ]);
    }

    /**
     * Process all recurring payments that are due.
     *
     * @return \Illuminate\Http\Response
     */
    
    /**
     * Batch pay all employees based on their role.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batchPayEmployees(Request $request)
    {
        $validated = $request->validate([
            'role' => 'required|in:teacher,assistant,all',
            'payment_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'is_recurring' => 'nullable|boolean',
            'frequency' => 'nullable|required_if:is_recurring,true|in:monthly,yearly,custom',
            'next_payment_date' => 'nullable|required_if:is_recurring,true|date',
        ]);

        $query = User::query();

        // Filter by role if not 'all'
        if ($validated['role'] !== 'all') {
            $query->where('role', $validated['role']);
        } else {
            $query->whereIn('role', ['teacher', 'assistant']);
        }

        $users = $query->get();
        
        $result = $this->processBatchPayment($users, $validated);
        
        if ($result['success']) {
            return redirect()->route('transactions.index')
                ->with('success', "Successfully processed payments for {$result['processed']} employees");
        } else {
            return redirect()->route('transactions.index')
                ->with('error', "Error processing batch payments: {$result['message']}");
        }
    }

    /**
     * View for batch payment form.
     *
     * @return \Illuminate\Http\Response
     */
    public function batchPaymentForm()
    {
        // Count of employees by role
        $teacherCount = User::where('role', 'teacher')->count();
        $assistantCount = User::where('role', 'assistant')->count();
        
        // Total of wallets and salaries
        $totalWallet = DB::table('teachers')
            ->join('users', 'teachers.email', '=', 'users.email')
            ->sum('wallet');
            
        $totalSalary = DB::table('assistants')
            ->join('users', 'assistants.email', '=', 'users.email')
            ->sum('salary');

        return Inertia::render('Menu/BatchPaymentPage', [
            'teacherCount' => $teacherCount,
            'assistantCount' => $assistantCount,
            'totalWallet' => $totalWallet,
            'totalSalary' => $totalSalary,
        ]);
    }

    /**
     * Process batch payment for multiple users
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $users
     * @param  array  $data
     * @return array
     */
    private function processBatchPayment($users, $data)
    {
        $processed = 0;

        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                $amount = 0;
                $type = '';
                
                if ($user->role === 'teacher') {
                    $teacher = DB::table('teachers')
                        ->where('email', $user->email)
                        ->first();
                    
                    if ($teacher && $teacher->wallet > 0) {
                        $amount = $teacher->wallet;
                        $type = 'payment'; // Changed from 'wallet' to 'payment' for teacher payments
                    }
                } elseif ($user->role === 'assistant') {
                    $assistant = DB::table('assistants')
                        ->where('email', $user->email)
                        ->first();
                    
                    if ($assistant && $assistant->salary > 0) {
                        $amount = $assistant->salary;
                        $type = 'salary';
                    }
                }

                if ($amount > 0) {
                    // Create a new transaction for this payment
                    $transaction = Transaction::create([
                        'type' => $type,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'amount' => $amount,
                        'description' => $data['description'] ?? "Monthly {$type} payment",
                        'payment_date' => $data['payment_date'],
                        'is_recurring' => $data['is_recurring'] ?? false,
                        'frequency' => $data['frequency'] ?? null,
                        'next_payment_date' => $data['next_payment_date'] ?? null,
                    ]);
                    
                    // Update the appropriate balance
                    $this->updateEmployeeBalance($transaction);
                    
                    $processed++;
                }
            }
            
            DB::commit();
            return [
                'success' => true,
                'processed' => $processed
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    /**
 * Process all recurring payments that are due.
 *
 * @return \Illuminate\Http\Response
 */
public function processRecurring()
{
    try {
        // Find all recurring transactions that are due for processing
        $dueTransactions = Transaction::where('is_recurring', true)
            ->whereDate('next_payment_date', '<=', now())
            ->get();
        
        // Process the transactions
        $processed = $this->processRecurringTransactions($dueTransactions);
        
        return redirect()->route('transactions.index')
            ->with('success', "Successfully processed {$processed} recurring transactions.");
    } catch (\Exception $e) {
        return redirect()->route('transactions.index')
            ->with('error', "Error processing recurring transactions: {$e->getMessage()}");
    }
}
    /**
     * Process recurring transactions
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $transactions
     * @return int
     */
    private function processRecurringTransactions($transactions)
    {
        $processed = 0;
        
        foreach ($transactions as $transaction) {
            // Create a new transaction based on the recurring one
            $newTransaction = $transaction->replicate();
            $newTransaction->payment_date = $transaction->next_payment_date;
            $newTransaction->created_at = now();
            $newTransaction->updated_at = now();
            $newTransaction->save();
            
            // Update employee balance
            if (in_array($transaction->type, ['salary', 'wallet'])) {
                $this->updateEmployeeBalance($newTransaction);
            }
            
            // Calculate the next payment date based on frequency
            $nextDate = $this->calculateNextPaymentDate($transaction);
            
            // Update the next payment date
            $transaction->next_payment_date = $nextDate;
            $transaction->save();
            
            $processed++;
        }
        
        return $processed;
    }

    /**
     * Calculate next payment date based on frequency
     *
     * @param  Transaction  $transaction
     * @return \Carbon\Carbon
     */
    private function calculateNextPaymentDate($transaction)
    {
        switch ($transaction->frequency) {
            case 'monthly':
                return Carbon::parse($transaction->next_payment_date)->addMonth();
            case 'yearly':
                return Carbon::parse($transaction->next_payment_date)->addYear();
            case 'custom':
            default:
                // For custom frequency, admin needs to set the next date manually
                // We'll just increment by 30 days as a fallback
                return Carbon::parse($transaction->next_payment_date)->addDays(30);
        }
    }

    /**
     * Validate transaction data
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    private function validateTransactionData(Request $request)
    {
        return $request->validate([
            'type' => 'required|in:salary,wallet,expense',
            'user_id' => 'nullable|exists:users,id',
            'user_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'rest' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'payment_date' => 'required|date',
            'is_recurring' => 'nullable|boolean',
            'frequency' => 'nullable|required_if:is_recurring,true|in:monthly,yearly,custom',
            'next_payment_date' => 'nullable|required_if:is_recurring,true|date',
        ]);
    }

    /**
     * Update employee balance based on transaction type and amount.
     *
     * @param  Transaction  $transaction
     * @return void
     */
    private function updateEmployeeBalance($transaction)
    {
        if (!$transaction->user_id) {
            return;
        }

        $user = User::find($transaction->user_id);
        if (!$user) {
            return;
        }

        if ($transaction->type === 'wallet' && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if ($teacher) {
                $teacher->increment('wallet', $transaction->amount);
            }
        } elseif ($transaction->type === 'payment' && $user->role === 'teacher') {
            // For payments to teachers, deduct from wallet
            $teacher = $user->teacher;
            if ($teacher) {
                $teacher->decrement('wallet', $transaction->amount);
            }
        }
        // For salary payments, we don't modify the salary field
    }


    /**
     * Revert the effect of a transaction on employee balance.
     *
     * @param  array|Transaction  $transaction
     * @return void
     */
    private function revertEmployeeBalance($transaction)
    {
        $type = $transaction['type'] ?? $transaction->type;
        $userId = $transaction['user_id'] ?? $transaction->user_id;
        $amount = $transaction['amount'] ?? $transaction->amount;
    
        if (!$userId) {
            return;
        }
    
        $user = User::find($userId);
        if (!$user) {
            return;
        }
    
        if ($type === 'wallet' && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if ($teacher) {
                $teacher->decrement('wallet', $amount);
            }
        } elseif ($type === 'payment' && $user->role === 'teacher') {
            // For payment reversals, add back to wallet
            $teacher = $user->teacher;
            if ($teacher) {
                $teacher->increment('wallet', $amount);
            }
        }
    }
    public function transactions($employeeId)
{
    // Fetch the employee's transactions
    $transactions = Transaction::with('user')
        ->where('user_id', $employeeId)
        ->orderBy('payment_date', 'desc')
        ->get();

    // Fetch the employee details
    $employee = User::findOrFail($employeeId);

    return Inertia::render('Payments/EmployeePaymentHistory', [
        'transactions' => $transactions,
        'employee' => $employee,
    ]);
}
 /**
     * Show the page for recurring transactions
     */
    public function showRecurringTransactions()
    {
        // Get all recurring transactions
        $recurringTransactions = Transaction::where('is_recurring', 1)
            ->with('user')  // Eager load the user relation
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'user_name' => $transaction->user ? $transaction->user->name : null,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'rest' => $transaction->rest,
                    'description' => $transaction->description,
                    'payment_date' => $transaction->payment_date,
                    'is_recurring' => $transaction->is_recurring,
                    'frequency' => $transaction->frequency,
                    'next_payment_date' => $transaction->next_payment_date,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at,
                ];
            });

        return Inertia::render('Payments/RecurringTransactionsPage', [
            'recurringTransactions' => $recurringTransactions
        ]);
    }
    /**
 * Display the list of recurring transactions with filter
 */
public function recurringTransactions(Request $request)
{
    $month = $request->month ?? Carbon::now()->format('Y-m');
    
    // Parse the month filter
    $startDate = Carbon::parse($month . '-01')->startOfMonth();
    $endDate = Carbon::parse($month . '-01')->endOfMonth();
    
    $recurringTransactions = Transaction::where('is_recurring', 1)
        ->where(function($query) use ($startDate, $endDate) {
            $query->whereBetween('next_payment_date', [$startDate, $endDate])
                ->orWhereNull('next_payment_date');
        })
        ->get();
    
    // Add payment status for each transaction
    foreach ($recurringTransactions as $transaction) {
        // Check if a corresponding one-time transaction exists for this month
        $isPaidThisMonth = Transaction::where('is_recurring', 0)
            ->where('description', 'like', '%(Recurring payment from #' . $transaction->id . ')%')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->exists();
        
        $transaction->paid_this_month = $isPaidThisMonth;
        
        // Get user name if available
        if ($transaction->user) {
            $transaction->user_name = $transaction->user->name;
        }
    }
    
    // Get list of available months for filter (last 12 months + next 12 months)
    $months = [];
    $currentMonth = Carbon::now()->subMonths(12);
    for ($i = 0; $i < 25; $i++) {
        $formattedMonth = $currentMonth->format('Y-m');
        $displayMonth = $currentMonth->format('F Y');
        $months[$formattedMonth] = $displayMonth;
        $currentMonth->addMonth();
    }
    
    return Inertia::render('Transactions/RecurringTransactions', [
        'recurringTransactions' => $recurringTransactions,
        'selectedMonth' => $month,
        'availableMonths' => $months,
    ]);
}

/**
 * Process all recurring transactions for a specific month
 */
public function processMonthRecurringTransactions(Request $request)
{
    try {
        $month = $request->month ?? Carbon::now()->format('Y-m');
        
        // Parse the month filter
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();
        
        $recurringTransactions = Transaction::where('is_recurring', 1)
            ->where(function($query) use ($startDate, $endDate) {
                $query->whereBetween('next_payment_date', [$startDate, $endDate])
                    ->orWhereNull('next_payment_date');
            })
            ->get();
        
        if ($recurringTransactions->isEmpty()) {
            return redirect()->back()->with('error', 'No recurring transactions found for this month.');
        }

        $count = 0;
        foreach ($recurringTransactions as $transaction) {
            // Check if this transaction has already been processed this month
            $alreadyProcessed = Transaction::where('is_recurring', 0)
                ->where('description', 'like', '%(Recurring payment from #' . $transaction->id . ')%')
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->exists();
            
            // Only process if not already processed
            if (!$alreadyProcessed) {
                // Create a new transaction based on the recurring one
                $newTransaction = new Transaction();
                $newTransaction->user_id = $transaction->user_id;
                $newTransaction->type = $transaction->type;
                $newTransaction->amount = $transaction->amount;
                $newTransaction->rest = $transaction->rest;
                $newTransaction->description = $transaction->description . ' (Recurring payment from #' . $transaction->id . ')';
                $newTransaction->payment_date = now();
                $newTransaction->is_recurring = 0; // This is a one-time transaction
                $newTransaction->save();

                // Update the next payment date of the recurring transaction
                $this->updateNextPaymentDate($transaction);
                
                $count++;
            }
        }

        if ($count > 0) {
            return redirect()->back()->with('success', $count . ' transactions processed successfully.');
        } else {
            return redirect()->back()->with('success', 'All transactions for this month have already been processed.');
        }
    } catch (\Exception $e) {
        return redirect()->back()->with('error', 'Error processing transactions: ' . $e->getMessage());
    }
}
    /**
     * Process a single recurring transaction
     */
    public function processSingleRecurringTransaction($id)
    {
        try {
            $transaction = Transaction::findOrFail($id);
            
            if (!$transaction->is_recurring) {
                return redirect()->back()->with('error', 'This is not a recurring transaction.');
            }

            // Create a new transaction based on the recurring one
            $newTransaction = new Transaction();
            $newTransaction->user_id = $transaction->user_id;
            $newTransaction->type = $transaction->type;
            $newTransaction->amount = $transaction->amount;
            $newTransaction->rest = $transaction->rest;
            $newTransaction->description = $transaction->description . ' (Recurring payment from #' . $transaction->id . ')';
            $newTransaction->payment_date = now();
            $newTransaction->is_recurring = 0; // This is a one-time transaction
            $newTransaction->save();

            // Update the next payment date of the recurring transaction
            $this->updateNextPaymentDate($transaction);

            return redirect()->back()->with('success', 'Transaction processed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error processing transaction: ' . $e->getMessage());
        }
    }

    /**
     * Process selected recurring transactions
     */
    public function processSelectedRecurringTransactions(Request $request)
    {
        try {
            $transactionIds = $request->transactions;
            
            if (empty($transactionIds)) {
                return redirect()->back()->with('error', 'No transactions selected.');
            }

            $count = 0;
            foreach ($transactionIds as $id) {
                $transaction = Transaction::find($id);
                
                if ($transaction && $transaction->is_recurring) {
                    // Create a new transaction based on the recurring one
                    $newTransaction = new Transaction();
                    $newTransaction->user_id = $transaction->user_id;
                    $newTransaction->type = $transaction->type;
                    $newTransaction->amount = $transaction->amount;
                    $newTransaction->rest = $transaction->rest;
                    $newTransaction->description = $transaction->description . ' (Recurring payment from #' . $transaction->id . ')';
                    $newTransaction->payment_date = now();
                    $newTransaction->is_recurring = 0; // This is a one-time transaction
                    $newTransaction->save();

                    // Update the next payment date of the recurring transaction
                    $this->updateNextPaymentDate($transaction);
                    
                    $count++;
                }
            }

            return redirect()->back()->with('success', $count . ' transactions processed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error processing transactions: ' . $e->getMessage());
        }
    }

    /**
     * Process all recurring transactions
     */
    public function processAllRecurringTransactions()
    {
        try {
            $recurringTransactions = Transaction::where('is_recurring', 1)->get();
            
            if ($recurringTransactions->isEmpty()) {
                return redirect()->back()->with('error', 'No recurring transactions found.');
            }

            $count = 0;
            foreach ($recurringTransactions as $transaction) {
                // Check if the transaction is due for processing
                $nextPaymentDate = Carbon::parse($transaction->next_payment_date);
                $today = Carbon::today();
                
                if ($nextPaymentDate->lte($today)) {
                    // Create a new transaction based on the recurring one
                    $newTransaction = new Transaction();
                    $newTransaction->user_id = $transaction->user_id;
                    $newTransaction->type = $transaction->type;
                    $newTransaction->amount = $transaction->amount;
                    $newTransaction->rest = $transaction->rest;
                    $newTransaction->description = $transaction->description . ' (Recurring payment from #' . $transaction->id . ')';
                    $newTransaction->payment_date = now();
                    $newTransaction->is_recurring = 0; // This is a one-time transaction
                    $newTransaction->save();

                    // Update the next payment date of the recurring transaction
                    $this->updateNextPaymentDate($transaction);
                    
                    $count++;
                }
            }

            if ($count > 0) {
                return redirect()->back()->with('success', $count . ' transactions processed successfully.');
            } else {
                return redirect()->back()->with('success', 'No transactions were due for processing.');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error processing transactions: ' . $e->getMessage());
        }
    }

    /**
     * Update the next payment date based on frequency
     */
    private function updateNextPaymentDate($transaction)
    {
        $currentNextPaymentDate = Carbon::parse($transaction->next_payment_date ?: $transaction->payment_date);
        
        switch ($transaction->frequency) {
            case 'daily':
                $nextPaymentDate = $currentNextPaymentDate->addDay();
                break;
            case 'weekly':
                $nextPaymentDate = $currentNextPaymentDate->addWeek();
                break;
            case 'biweekly':
                $nextPaymentDate = $currentNextPaymentDate->addWeeks(2);
                break;
            case 'monthly':
                $nextPaymentDate = $currentNextPaymentDate->addMonth();
                break;
            case 'quarterly':
                $nextPaymentDate = $currentNextPaymentDate->addMonths(3);
                break;
            case 'semiannually':
                $nextPaymentDate = $currentNextPaymentDate->addMonths(6);
                break;
            case 'annually':
                $nextPaymentDate = $currentNextPaymentDate->addYear();
                break;
            default:
                $nextPaymentDate = $currentNextPaymentDate->addMonth(); // Default to monthly
        }
        
        $transaction->next_payment_date = $nextPaymentDate;
        $transaction->save();
    }
}