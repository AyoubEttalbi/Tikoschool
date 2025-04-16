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
        'users' => $usersWithDetails->toArray(),
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
 * Get all available years from the database
 * 
 * @return array
 */
private function getAvailableYears()
{
    try {
        $years = DB::table('invoices')
            ->select(DB::raw('DISTINCT EXTRACT(YEAR FROM "billDate") as year'))
            ->whereNull('deleted_at')
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();
            
        // Convert to integers
        $years = array_map('intval', $years);
        
        Log::info('Available years from DB:', $years);
        
        // If no years found, use current year
        if (empty($years)) {
            $years = [now()->year];
        }
        
        return $years;
    } catch (\Exception $e) {
        Log::error('Error getting available years: ' . $e->getMessage());
        // Return current year as fallback
        return [now()->year];
    }
}

/**
 * Check if a table exists in the database
 *
 * @param string $tableName
 * @return bool
 */
private function tableExists($tableName)
{
    try {
        // For MySQL
        $result = DB::select(
            "SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = ?", [$tableName]
        );
        return !empty($result[0]->table_exists);
    } catch (\Exception $e) {
        Log::error('Error checking if table exists: ' . $e->getMessage());
        // If any error occurs, assume table doesn't exist
        return false;
    }
}

/**
 * Calculate admin earnings per month for the last 12 months
 *
 * @return array
 */
private function calculateAdminEarningsPerMonth()
{
    // Enable query logging for debugging
    DB::enableQueryLog();
    
    // Get invoices from last 12 months
    $startDate = now()->subMonths(11)->startOfMonth();
    
    // Get monthly invoice earnings
    $monthlyEarnings = $this->getMonthlyInvoiceEarnings($startDate);
    
    // Initialize array for all months (including months with zero earnings)
    $allMonths = $this->initializeAllMonths();
    
    // Fill in actual earnings data
    $allMonths = $this->fillMonthlyEarnings($allMonths, $monthlyEarnings);
    
    // Process earnings with additional metrics
    $processedEarnings = $this->processMonthlyEarnings($allMonths, $monthlyEarnings);
    
    // Sort by year and month (descending)
    return $this->sortProcessedEarnings($processedEarnings);
}

/**
 * Get monthly invoice earnings
 *
 * @param \Carbon\Carbon $startDate
 * @return \Illuminate\Support\Collection
 */
private function getMonthlyInvoiceEarnings($startDate)
{
    try {
        // Log sample data for debugging
        $this->logSampleInvoices($startDate);
        
        // Get all invoices
        $allInvoices = DB::table('invoices')
            ->select('id', 'billDate', 'amountPaid')
            ->whereNull('deleted_at')
            ->get();
            
        Log::info('All invoices count:', ['count' => $allInvoices->count()]);
        
        // Group earnings by year and month
        $groupedEarnings = $this->groupInvoicesByMonth($allInvoices);
        
        // Convert to collection and sort
        $monthlyEarnings = collect(array_values($groupedEarnings));
        $monthlyEarnings = $monthlyEarnings->sortByDesc(function ($item) {
            return ($item['year'] * 100) + $item['month'];
        })->values();
        
        Log::info('Manually calculated monthly earnings:', [
            'count' => $monthlyEarnings->count(),
            'data' => $monthlyEarnings->toArray()
        ]);
        
        return $monthlyEarnings;
    } catch (\Exception $e) {
        Log::error('Error querying monthly earnings: ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());
        return collect([]);
    }
}

/**
 * Log sample invoices for debugging
 *
 * @param \Carbon\Carbon $startDate
 * @return void
 */
private function logSampleInvoices($startDate)
{
    $sampleInvoices = DB::table('invoices')
        ->select('id', 'billDate', 'amountPaid', 'created_at')
        ->limit(5)
        ->get();
    
    Log::info('Sample invoices for debugging:', [
        'sample_data' => $sampleInvoices->toArray(),
        'start_date' => $startDate->format('Y-m-d')
    ]);
}

/**
 * Group invoices by month
 *
 * @param \Illuminate\Support\Collection $invoices
 * @return array
 */
private function groupInvoicesByMonth($invoices)
{
    $groupedEarnings = [];
    
    foreach ($invoices as $invoice) {
        $date = Carbon::parse($invoice->billDate);
        $year = $date->year;
        $month = $date->month;
        $key = "$year-$month";
        
        if (!isset($groupedEarnings[$key])) {
            $groupedEarnings[$key] = [
                'year' => $year,
                'month' => $month,
                'totalPaid' => 0
            ];
        }
        
        $groupedEarnings[$key]['totalPaid'] += (float)$invoice->amountPaid;
    }
    
    return $groupedEarnings;
}

/**
 * Initialize array for all months in the last year
 *
 * @return array
 */
private function initializeAllMonths()
{
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
    
    return $allMonths;
}

/**
 * Fill monthly earnings data into all months array
 *
 * @param array $allMonths
 * @param \Illuminate\Support\Collection $monthlyEarnings
 * @return array
 */
private function fillMonthlyEarnings($allMonths, $monthlyEarnings)
{
    foreach ($monthlyEarnings as $earning) {
        $yearMonth = $earning['year'] . '-' . sprintf('%02d', $earning['month']);
        if (isset($allMonths[$yearMonth])) {
            // Make sure to cast to float to avoid string issues
            $allMonths[$yearMonth]['totalPaid'] = (float)($earning['totalPaid'] ?? 0);
            Log::info("Setting totalPaid for $yearMonth:", ['amount' => (float)($earning['totalPaid'] ?? 0)]);
        }
    }
    
    return $allMonths;
}

/**
 * Process monthly earnings with additional metrics
 *
 * @param array $allMonths
 * @param \Illuminate\Support\Collection $monthlyEarnings
 * @return array
 */
private function processMonthlyEarnings($allMonths, $monthlyEarnings)
{
    $processedEarnings = [];
    
    // Log the monthly earnings before processing
    Log::info('Monthly earnings before processing:', [
        'monthlyEarnings' => $monthlyEarnings->toArray()
    ]);
    
    foreach ($allMonths as $yearMonth => $data) {
        // Find matching entry from manually calculated earnings
        $matchingEarning = $this->findMatchingEarning($monthlyEarnings, $data);
        
        // Get invoice revenue
        $invoiceRevenue = $matchingEarning ? (float)$matchingEarning['totalPaid'] : (float)$data['totalPaid'];
        
        // Log what we found
        $this->logMatchingEarning($yearMonth, $matchingEarning, $invoiceRevenue);
        
        // Get monthly enrollment revenue
        $monthlyEnrollmentRevenue = $this->getMonthlyEnrollmentRevenue($data['year'], $data['month']);
        
        // Get monthly expenses
        $monthlyExpenses = $this->getMonthlyExpenses($data['year'], $data['month']);
        
        // Calculate totals
        $totalRevenue = $invoiceRevenue + (float)$monthlyEnrollmentRevenue;
        $profit = $totalRevenue - (float)$monthlyExpenses;
        
        // Log calculation results
        $this->logMonthCalculationResults($yearMonth, $invoiceRevenue, $monthlyEnrollmentRevenue, $totalRevenue, $monthlyExpenses, $profit);
        
        // Add to processed earnings
        $processedEarnings[] = [
            'year' => $data['year'],
            'month' => $data['month'],
            'monthName' => $data['monthName'],
            'totalRevenue' => $totalRevenue,
            'totalExpenses' => (float)$monthlyExpenses,
            'profit' => $profit,
            'yearMonth' => $yearMonth
        ];
    }
    
    return $processedEarnings;
}

/**
 * Find matching earning from monthly earnings
 *
 * @param \Illuminate\Support\Collection $monthlyEarnings
 * @param array $data
 * @return array|null
 */
private function findMatchingEarning($monthlyEarnings, $data)
{
    return $monthlyEarnings->first(function ($item) use ($data) {
        return $item['year'] == $data['year'] && $item['month'] == $data['month'];
    });
}

/**
 * Log matching earning information
 *
 * @param string $yearMonth
 * @param array|null $matchingEarning
 * @param float $invoiceRevenue
 * @return void
 */
private function logMatchingEarning($yearMonth, $matchingEarning, $invoiceRevenue)
{
    Log::info("Matching earning for $yearMonth:", [
        'found' => $matchingEarning ? 'yes' : 'no',
        'invoiceRevenue' => $invoiceRevenue,
        'originalData' => $matchingEarning ?? 'not found'
    ]);
}

/**
 * Get monthly enrollment revenue
 *
 * @param int $year
 * @param int $month
 * @return float
 */
private function getMonthlyEnrollmentRevenue($year, $month)
{
    $monthlyEnrollmentRevenue = 0;
    
    if ($this->tableExists('enrollments')) {
        try {
            $monthlyEnrollmentRevenue = DB::table('enrollments')
                ->join('courses', 'enrollments.course_id', '=', 'courses.id')
                ->whereRaw('EXTRACT(YEAR FROM enrollments.created_at) = ?', [$year])
                ->whereRaw('EXTRACT(MONTH FROM enrollments.created_at) = ?', [$month])
                ->whereNull('enrollments.deleted_at')
                ->sum(DB::raw('CAST(courses.price AS DECIMAL(10,2))'));
        } catch (\Exception $e) {
            Log::error('Error querying enrollment revenue: ' . $e->getMessage());
            $monthlyEnrollmentRevenue = 0;
        }
    }
    
    return $monthlyEnrollmentRevenue;
}

/**
 * Get monthly expenses
 *
 * @param int $year
 * @param int $month
 * @return float
 */
private function getMonthlyExpenses($year, $month)
{
    $monthlyExpenses = 0;
    
    try {
        $monthlyExpenses = DB::table('transactions')
            ->where(function ($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment')
                      ->orWhere('type', 'expense');
            })
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
            ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
            ->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
    } catch (\Exception $e) {
        Log::error('Error querying monthly expenses: ' . $e->getMessage());
        $monthlyExpenses = 0;
    }
    
    return $monthlyExpenses;
}

/**
 * Log month calculation results
 *
 * @param string $yearMonth
 * @param float $invoiceRevenue
 * @param float $monthlyEnrollmentRevenue
 * @param float $totalRevenue
 * @param float $monthlyExpenses
 * @param float $profit
 * @return void
 */
private function logMonthCalculationResults($yearMonth, $invoiceRevenue, $monthlyEnrollmentRevenue, $totalRevenue, $monthlyExpenses, $profit)
{
    Log::info("Month $yearMonth calculation results:", [
        'invoiceRevenue' => $invoiceRevenue,
        'monthlyEnrollmentRevenue' => (float)$monthlyEnrollmentRevenue,
        'totalRevenue' => $totalRevenue,
        'monthlyExpenses' => (float)$monthlyExpenses,
        'profit' => $profit
    ]);
}

/**
 * Sort processed earnings by year and month (descending)
 *
 * @param array $processedEarnings
 * @return array
 */
private function sortProcessedEarnings($processedEarnings)
{
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
    
    // Create a simple array with hardcoded data based on our database check
    $processedEarnings = [];
    
    // Current month and year
    $currentMonth = now()->month;
    $currentYear = now()->year;
    
    // Get the actual invoice data directly from the database
    $invoiceData = DB::table('invoices')
        ->select('id', 'billDate', 'totalAmount', 'amountPaid')
        ->whereNull('deleted_at')
        ->get();
    
    Log::info('Raw invoice data:', [
        'count' => $invoiceData->count(),
        'data' => $invoiceData->toArray()
    ]);
    
    // Group invoices by month
    $monthlyData = [];
    foreach ($invoiceData as $invoice) {
        $date = Carbon::parse($invoice->billDate);
        $year = $date->year;
        $month = $date->month;
        
        $key = "$year-$month";
        if (!isset($monthlyData[$key])) {
            $monthlyData[$key] = [
                'year' => $year,
                'month' => $month,
                'totalRevenue' => 0,
                'totalExpenses' => 0
            ];
        }
        
        $monthlyData[$key]['totalRevenue'] += (float)$invoice->totalAmount;
    }
    
    // Get monthly expenses
    foreach ($monthlyData as $key => $data) {
        $expenses = DB::table('transactions')
            ->where(function ($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment')
                      ->orWhere('type', 'expense');
            })
            ->whereYear('payment_date', $data['year'])
            ->whereMonth('payment_date', $data['month'])
            ->sum('amount');
        
        $monthlyData[$key]['totalExpenses'] = (float)$expenses;
        $monthlyData[$key]['profit'] = $monthlyData[$key]['totalRevenue'] - $monthlyData[$key]['totalExpenses'];
        $monthlyData[$key]['monthName'] = Carbon::createFromDate($data['year'], $data['month'], 1)->format('F');
    }
    
    // Make sure we have entries for the last 12 months
    for ($i = 0; $i < 12; $i++) {
        $date = now()->subMonths($i);
        $year = $date->year;
        $month = $date->month;
        $key = "$year-$month";
        
        if (!isset($monthlyData[$key])) {
            $monthlyData[$key] = [
                'year' => $year,
                'month' => $month,
                'monthName' => $date->format('F'),
                'totalRevenue' => 0,
                'totalExpenses' => 0,
                'profit' => 0
            ];
        }
        
        // Add yearMonth key needed by frontend
        $monthlyData[$key]['yearMonth'] = $key;
        
        // Add to processed earnings
        $processedEarnings[] = $monthlyData[$key];
    }
    
    // Sort by year and month (descending)
    usort($processedEarnings, function ($a, $b) {
        if ($a['year'] != $b['year']) {
            return $b['year'] <=> $a['year']; // Latest year first
        }
        return $b['month'] <=> $a['month']; // Latest month first
    });
    
    // Log the final data
    Log::info('Final earnings data:', [
        'count' => count($processedEarnings),
        'data' => $processedEarnings
    ]);
    
    // Include available years in the response
    return response()->json([
        'earnings' => $processedEarnings,
        'availableYears' => $availableYears
    ]);
}



/**
 * Direct debug method to check raw invoice data
 *
 * @return \Illuminate\Http\JsonResponse
 */
public function debugInvoiceData()
{
    $result = [
        'success' => true,
        'message' => 'Invoice data debug'
    ];
    
    try {
        // Count invoices
        $invoiceCount = DB::table('invoices')->whereNull('deleted_at')->count();
        $result['invoiceCount'] = $invoiceCount;
        
        // Get raw invoice data (first 10)
        $rawInvoices = DB::table('invoices')
            ->select('id', 'billDate', 'amountPaid', 'deleted_at')
            ->whereNull('deleted_at')
            ->orderBy('billDate', 'desc')
            ->limit(10)
            ->get();
        
        $result['rawInvoices'] = $rawInvoices;
        
        // Check column types
        $columnCheck = DB::select("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'invoices' 
            AND (column_name = 'amountpaid' OR column_name = 'billdate')
        ");
        
        $result['columnInfo'] = $columnCheck;
        
        // Get yearly totals
        $yearlyTotals = DB::table('invoices')
            ->select(
                DB::raw('EXTRACT(YEAR FROM "billDate") as year'),
                DB::raw('SUM(CAST("amountPaid" AS DECIMAL(10,2))) as yearTotal')
            )
            ->whereNull('deleted_at')
            ->groupBy(DB::raw('EXTRACT(YEAR FROM "billDate")'))
            ->orderBy('year', 'desc')
            ->get();
        
        $result['yearlyTotals'] = $yearlyTotals;
        
    } catch (\Exception $e) {
        $result['success'] = false;
        $result['error'] = $e->getMessage();
    }
    
    return response()->json($result);
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
    
    // Log for debugging
    Log::info('Admin earnings calculation start date:', ['startDate' => $startDate->format('Y-m-d')]);
    
    // Get all paid amounts from invoices, grouped by month and year
    try {
        $monthlyEarnings = DB::table('invoices')
            ->select(
                DB::raw('EXTRACT(YEAR FROM "billDate") as year'),
                DB::raw('EXTRACT(MONTH FROM "billDate") as month'),
                DB::raw('SUM(CAST("amountPaid" AS DECIMAL(10,2))) as totalPaid')
            )
            ->whereNull('deleted_at')
            ->where('billDate', '>=', $startDate)
            ->groupBy(DB::raw('EXTRACT(YEAR FROM "billDate")'), DB::raw('EXTRACT(MONTH FROM "billDate")'))
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();
        
        Log::info('Monthly earnings raw data:', [
            'count' => $monthlyEarnings->count(),
            'first_records' => $monthlyEarnings->take(3)->toArray()
        ]);
    } catch (\Exception $e) {
        Log::error('Error querying monthly earnings: ' . $e->getMessage());
        $monthlyEarnings = collect([]);
    }
    
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
            // Make sure to cast to float to avoid string issues
            $allMonths[$yearMonth]['totalPaid'] = (float)($earning->totalPaid ?? 0);
            Log::info("Setting totalPaid for $yearMonth:", ['amount' => (float)($earning->totalPaid ?? 0)]);
        }
    }
    
    // Calculate additional metrics for each month
    $processedEarnings = [];
    foreach ($allMonths as $yearMonth => $data) {
        // Get total expenses for this month (teacher wallets + assistant salaries + expenses)
        $monthDate = Carbon::createFromDate($data['year'], $data['month'], 1);
        
        // Add revenue from course enrollments
        $monthlyEnrollmentRevenue = 0;
        if ($this->tableExists('enrollments')) {
            try {
                $monthlyEnrollmentRevenue = DB::table('enrollments')
                    ->join('courses', 'enrollments.course_id', '=', 'courses.id')
                    ->whereRaw('EXTRACT(YEAR FROM enrollments.created_at) = ?', [$data['year']])
                    ->whereRaw('EXTRACT(MONTH FROM enrollments.created_at) = ?', [$data['month']])
                    ->whereNull('enrollments.deleted_at')
                    ->sum(DB::raw('CAST(courses.price AS DECIMAL(10,2))'));
            } catch (\Exception $e) {
                Log::error('Error querying enrollment revenue: ' . $e->getMessage());
                $monthlyEnrollmentRevenue = 0;
            }
        }
            
        // Get existing monthly expenses
        $monthlyExpenses = 0;
        try {
            $monthlyExpenses = DB::table('transactions')
                ->where(function ($query) {
                    $query->where('type', 'salary')
                          ->orWhere('type', 'payment')
                          ->orWhere('type', 'expense');
                })
                ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$data['year']])
                ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$data['month']])
                ->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
        } catch (\Exception $e) {
            Log::error('Error querying monthly expenses: ' . $e->getMessage());
            $monthlyExpenses = 0;
        }
        
        // Calculate total revenue (invoices + enrollments)
        $invoiceRevenue = (float)$data['totalPaid'];
        $totalRevenue = $invoiceRevenue + (float)$monthlyEnrollmentRevenue;
        
        // Calculate profit
        $profit = $totalRevenue - (float)$monthlyExpenses;
        
        Log::info("Month $yearMonth calculation results:", [
            'invoiceRevenue' => $invoiceRevenue,
            'monthlyEnrollmentRevenue' => (float)$monthlyEnrollmentRevenue,
            'totalRevenue' => $totalRevenue,
            'monthlyExpenses' => (float)$monthlyExpenses,
            'profit' => $profit
        ]);
        
        $processedEarnings[] = [
            'year' => $data['year'],
            'month' => $data['month'],
            'monthName' => $data['monthName'],
            'totalRevenue' => $totalRevenue,
            'totalExpenses' => (float)$monthlyExpenses,
            'profit' => $profit,
            'yearMonth' => $yearMonth
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
public function index(Request $request)
{
    // Get filter parameters
    $year = $request->query('year', now()->year);
    
    // Get all transactions, with latest first
    $transactions = Transaction::with('user')
        ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
        ->orderBy('payment_date', 'desc')
        ->paginate(10);

    // Calculate total amount for the filtered transactions
    $totalAmount = Transaction::whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
        ->sum('amount');

    // Get all available years for the filter
    $availableYears = DB::table('transactions')
        ->select(DB::raw('DISTINCT EXTRACT(YEAR FROM payment_date) as year'))
        ->orderBy('year', 'desc')
        ->pluck('year')
        ->toArray();

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
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            // Log the incoming request data
            Log::info('Transaction store request', [
                'data' => $request->all(),
                'client_ip' => $request->ip()
            ]);

            // Validate the request
            $validated = $request->validate([
                'type' => 'required|string|in:wallet,payment,salary',
                'user_id' => 'required|exists:users,id',
                'amount' => 'required|numeric|min:0.01',
                'description' => 'nullable|string|max:255',
                'recurring' => 'nullable|boolean',
                'frequency' => 'nullable|required_if:recurring,1|in:weekly,monthly,quarterly,yearly',
                'next_payment_date' => 'nullable|required_if:recurring,1|date',
                'payment_date' => 'nullable|date',
            ]);
            
            // Set payment_date to today if not provided
            if (!isset($validated['payment_date'])) {
                $validated['payment_date'] = now();
            }
            
            // For salary or payment transactions, check if the user has already been paid this month
            if (in_array($validated['type'], ['salary', 'payment'])) {
                $paymentDate = Carbon::parse($validated['payment_date']);
                $month = $paymentDate->month;
                $year = $paymentDate->year;
                
                // Check for existing payments in the same month/year
                $existingPayment = Transaction::where('user_id', $validated['user_id'])
                    ->whereIn('type', ['salary', 'payment'])
                    ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
                    ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
                    ->exists();
                
                if ($existingPayment) {
                    Log::warning('Transaction store prevented: Duplicate payment', [
                        'user_id' => $validated['user_id'],
                        'month' => $month,
                        'year' => $year,
                        'type' => $validated['type']
                    ]);
                    
                    return back()->with('error', 'This employee has already been paid for ' . $paymentDate->format('F Y'));
                }
            }

            // Special validation for payment transactions
            if ($validated['type'] === 'payment') {
                $user = User::find($validated['user_id']);
                
                if (!$user) {
                    Log::error('Transaction store failed: User not found', [
                        'user_id' => $validated['user_id']
                    ]);
                    return back()->with('error', 'User not found');
                }
                
                // Check if user is a teacher for payment transactions
                if ($user->role === 'teacher') {
                    $teacher = $user->teacher;
                    
                    if (!$teacher) {
                        Log::error('Transaction store failed: Teacher model not found', [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        return back()->with('error', 'Teacher profile not found');
                    }
                    
                    // Check if teacher has 0 wallet
                    if ($teacher->wallet <= 0) {
                        Log::warning('Transaction store failed: Teacher has zero wallet balance', [
                            'teacher_id' => $teacher->id,
                            'wallet_balance' => $teacher->wallet
                        ]);
                        return back()->with('error', 'Cannot process payment for teacher with zero wallet balance');
                    }
                    
                    // Check wallet balance
                    if ($teacher->wallet < $validated['amount']) {
                        Log::warning('Transaction store failed: Insufficient wallet balance', [
                            'teacher_id' => $teacher->id,
                            'wallet_balance' => $teacher->wallet,
                            'payment_amount' => $validated['amount']
                        ]);
                        return back()->with('error', 'Insufficient wallet balance');
                    }
                    
                    Log::info('Teacher payment validation passed', [
                        'teacher_id' => $teacher->id,
                        'wallet_balance' => $teacher->wallet,
                        'payment_amount' => $validated['amount']
                    ]);
                }
            } else if ($validated['type'] === 'salary') {
                $user = User::find($validated['user_id']);
                
                if (!$user || $user->role !== 'assistant') {
                    return back()->with('error', 'Salary payments are only for assistants');
                }
                
                $assistant = $user->assistant;
                if (!$assistant) {
                    return back()->with('error', 'Assistant profile not found');
                }
                
                // Get the month/year from payment date
                $paymentDate = Carbon::parse($validated['payment_date']);
                $month = $paymentDate->month;
                $year = $paymentDate->year;
                
                // Calculate how much has already been paid this month
                $alreadyPaid = Transaction::where('user_id', $user->id)
                    ->where('type', 'salary')
                    ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
                    ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
                    ->sum('amount');
                
                // Calculate remaining salary
                $baseSalary = $assistant->salary;
                $remainingSalary = $baseSalary - $alreadyPaid;
                
                Log::info('Assistant salary check', [
                    'assistant_id' => $assistant->id,
                    'base_salary' => $baseSalary,
                    'already_paid' => $alreadyPaid,
                    'remaining_salary' => $remainingSalary,
                    'payment_amount' => $validated['amount']
                ]);
                
                // Check if assistant has already been paid their full salary
                if ($alreadyPaid >= $baseSalary) {
                    return back()->with('error', 'Assistant has already received their full salary for ' . $paymentDate->format('F Y'));
                }
                
                // Check if payment exceeds remaining salary
                if ($validated['amount'] > $remainingSalary) {
                    return back()->with('error', 'Payment amount exceeds remaining salary for ' . $paymentDate->format('F Y') . 
                        '. Remaining: ' . $remainingSalary . ', Requested: ' . $validated['amount']);
                }
            }

            // Create transaction
            $transaction = new Transaction($validated);
            $transaction->save();
            
            Log::info('Transaction created successfully', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount
            ]);
            
            // Update employee balance based on transaction type
            try {
                $this->updateEmployeeBalance($transaction);
            } catch (\Exception $e) {
                // If balance update fails, delete the transaction and return error
                Log::error('Transaction store failed during balance update', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
                
                $transaction->delete();
                
                return back()->with('error', $e->getMessage());
            }
            
            // Return an Inertia redirect instead of JSON response
            return redirect()->route('transactions.index')->with('success', 'Transaction created successfully');
            
        } catch (ValidationException $e) {
            Log::warning('Transaction store validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            
            return back()->withErrors($e->errors())->withInput();
            
        } catch (\Exception $e) {
            Log::error('Transaction store exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return back()->with('error', 'Error creating transaction: ' . $e->getMessage());
        }
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

        // Get the month/year from the payment date
        $paymentDate = Carbon::parse($validated['payment_date']);
        $month = $paymentDate->month;
        $year = $paymentDate->year;
        
        // Find employees who have already been paid this month
        $alreadyPaidUserIds = Transaction::whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
            ->where(function($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment');
            })
            ->pluck('user_id')
            ->toArray();
        
        // Query to get eligible users
        $query = User::with(['teacher', 'assistant']);

        // Filter by role if not 'all'
        if ($validated['role'] !== 'all') {
            $query->where('role', $validated['role']);
        } else {
            $query->whereIn('role', ['teacher', 'assistant']);
        }
        
        // Filter out already paid users
        $query->whereNotIn('id', $alreadyPaidUserIds);

        // Get the users
        $allUsers = $query->get();
        
        // Filter out teachers with zero wallet balance
        $eligibleUsers = $allUsers->filter(function($user) {
            // For teachers, check wallet balance
            if ($user->role === 'teacher') {
                return $user->teacher && $user->teacher->wallet > 0;
            }
            
            // All assistants are eligible
            return true;
        });
        
        if ($eligibleUsers->isEmpty()) {
            $message = "No eligible employees found for payment.";
            
            // Provide more specific information
            if ($validated['role'] === 'teacher') {
                $zeroWalletCount = $allUsers->filter(function($user) {
                    return $user->role === 'teacher' && (!$user->teacher || $user->teacher->wallet <= 0);
                })->count();
                
                if ($zeroWalletCount > 0) {
                    $message .= " Found {$zeroWalletCount} teachers with zero wallet balance.";
                }
            }
            
            return redirect()->route('transactions.index')
                ->with('warning', $message);
        }
        
        $result = $this->processBatchPayment($eligibleUsers, $validated);
        
        if ($result['success']) {
            return redirect()->route('transactions.index')->with('success', $result['message']);
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
    public function batchPaymentForm(Request $request)
    {
        // Default to current month/year
        $selectedDate = $request->input('payment_date') ? 
            Carbon::parse($request->input('payment_date')) : 
            Carbon::now();
        
        $month = $selectedDate->month;
        $year = $selectedDate->year;
        
        // Find employees who have already been paid this month
        $alreadyPaidUserIds = Transaction::whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
            ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
            ->where(function($query) {
                $query->where('type', 'salary')
                      ->orWhere('type', 'payment');
            })
            ->pluck('user_id')
            ->toArray();
        
        // Get all employees with their details
        $allUsers = User::with(['teacher', 'assistant'])
            ->whereIn('role', ['teacher', 'assistant'])
            ->get();
        
        // Filter users: exclude teachers with 0 wallet and already paid employees
        $eligibleUsers = $allUsers->filter(function($user) use ($alreadyPaidUserIds) {
            // Skip users who have already been paid this month
            if (in_array($user->id, $alreadyPaidUserIds)) {
                return false;
            }
            
            // For teachers, check wallet balance
            if ($user->role === 'teacher') {
                return $user->teacher && $user->teacher->wallet > 0;
            }
            
            // For assistants, include all who haven't been paid yet
            return $user->role === 'assistant' && $user->assistant;
        });
        
        // Paid users (for reference display)
        $paidUsers = $allUsers->filter(function($user) use ($alreadyPaidUserIds) {
            return in_array($user->id, $alreadyPaidUserIds);
        });
        
        // Zero wallet teachers (for reference display)
        $zeroWalletTeachers = $allUsers->filter(function($user) use ($alreadyPaidUserIds) {
            return $user->role === 'teacher' && 
                   (!$user->teacher || $user->teacher->wallet <= 0) && 
                   !in_array($user->id, $alreadyPaidUserIds);
        });
        
        // Count eligible employees by role
        $eligibleTeacherCount = $eligibleUsers->where('role', 'teacher')->count();
        $eligibleAssistantCount = $eligibleUsers->where('role', 'assistant')->count();
        
        // Count paid and ineligible employees
        $paidTeacherCount = $paidUsers->where('role', 'teacher')->count();
        $paidAssistantCount = $paidUsers->where('role', 'assistant')->count();
        $zeroWalletTeacherCount = $zeroWalletTeachers->count();
        
        // Total of wallets and salaries for eligible employees
        $totalWallet = 0;
        $totalSalary = 0;
        
        foreach ($eligibleUsers as $user) {
            if ($user->role === 'teacher' && $user->teacher) {
                $totalWallet += $user->teacher->wallet ?? 0;
            } elseif ($user->role === 'assistant' && $user->assistant) {
                $totalSalary += $user->assistant->salary ?? 0;
            }
        }

        return Inertia::render('Menu/BatchPaymentPage', [
            'unpaidTeacherCount' => $eligibleTeacherCount,
            'unpaidAssistantCount' => $eligibleAssistantCount,
            'paidTeacherCount' => $paidTeacherCount,
            'paidAssistantCount' => $paidAssistantCount,
            'zeroWalletTeacherCount' => $zeroWalletTeacherCount,
            'totalWallet' => $totalWallet,
            'totalSalary' => $totalSalary,
            'selectedMonth' => $selectedDate->format('F Y'),
            'alreadyPaidCount' => count($alreadyPaidUserIds),
            'paidUsers' => $paidUsers->values()->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role
                ];
            }),
            'zeroWalletTeachers' => $zeroWalletTeachers->values()->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'wallet' => $user->teacher ? $user->teacher->wallet : 0
                ];
            }),
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
        $skipped = 0;
        $alreadyPaid = 0;
        $zeroWallet = 0;

        // Get the payment date
        $paymentDate = Carbon::parse($data['payment_date']);
        $month = $paymentDate->month;
        $year = $paymentDate->year;

        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                // Final check that user hasn't been paid this month/year
                $existingPayment = Transaction::where('user_id', $user->id)
                    ->whereIn('type', ['salary', 'payment'])
                    ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
                    ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
                    ->exists();
                    
                if ($existingPayment) {
                    $alreadyPaid++;
                    Log::warning('Batch payment skipped: Already paid this month', [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'month' => $month,
                        'year' => $year
                    ]);
                    continue;
                }
                
                $amount = 0;
                $type = '';
                
                if ($user->role === 'teacher') {
                    $teacher = $user->teacher ?? DB::table('teachers')
                        ->where('email', $user->email)
                        ->first();
                    
                    if ($teacher && $teacher->wallet > 0) {
                        $amount = $teacher->wallet;
                        $type = 'payment'; // Changed from 'wallet' to 'payment' for teacher payments
                    } else {
                        $zeroWallet++;
                        Log::warning('Batch payment skipped: Teacher has zero wallet balance', [
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                            'wallet' => $teacher->wallet ?? 0
                        ]);
                        continue;
                    }
                } elseif ($user->role === 'assistant') {
                    $assistant = $user->assistant ?? DB::table('assistants')
                        ->where('email', $user->email)
                        ->first();
                    
                    if (!$assistant || !$assistant->salary || $assistant->salary <= 0) {
                        $skipped++;
                        Log::warning('Batch payment skipped: Assistant has zero or missing salary', [
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                        ]);
                        continue;
                    }
                    
                    // Calculate how much has already been paid this month
                    $alreadyPaid = Transaction::where('user_id', $user->id)
                        ->where('type', 'salary')
                        ->whereRaw('EXTRACT(MONTH FROM payment_date) = ?', [$month])
                        ->whereRaw('EXTRACT(YEAR FROM payment_date) = ?', [$year])
                        ->sum('amount');
                    
                    // If they've already received full or partial payment
                    if ($alreadyPaid > 0) {
                        $baseSalary = $assistant->salary;
                        $remainingSalary = $baseSalary - $alreadyPaid;
                        
                        // If they've received their full salary already
                        if ($alreadyPaid >= $baseSalary) {
                            Log::warning('Batch payment skipped: Assistant already received full salary', [
                                'user_id' => $user->id,
                                'user_name' => $user->name,
                                'base_salary' => $baseSalary,
                                'already_paid' => $alreadyPaid
                            ]);
                            $skipped++;
                            continue;
                        }
                        
                        // Pay only the remaining amount
                        $amount = $remainingSalary;
                        Log::info('Batch payment adjusted for partial payment: Paying remaining salary', [
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                            'base_salary' => $baseSalary,
                            'already_paid' => $alreadyPaid,
                            'paying_now' => $amount
                        ]);
                    } else {
                        $amount = $assistant->salary;
                    }
                    
                    $type = 'salary';
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
                } else {
                    $skipped++;
                }
            }
            
            DB::commit();
            $message = "";
            if ($alreadyPaid > 0) {
                $message = "{$alreadyPaid} employees were skipped because they were already paid this month. ";
            }
            if ($zeroWallet > 0) {
                $message .= "{$zeroWallet} teachers were skipped because they have zero wallet balance. ";
            }
            
            return [
                'success' => true,
                'processed' => $processed,
                'message' => $message . "Successfully processed payments for {$processed} employees."
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
            'type' => 'required|in:salary,wallet,payment,expense',
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
     * Update employee balance based on transaction type.
     *
     * @param  \App\Models\Transaction  $transaction
     * @return void
     */
    private function updateEmployeeBalance($transaction)
    {
        try {
            if (empty($transaction->user_id)) {
                Log::warning('updateEmployeeBalance: Transaction has no user_id', [
                    'transaction_id' => $transaction->id,
                    'type' => $transaction->type
                ]);
                return;
            }

            $user = User::find($transaction->user_id);
            if (!$user) {
                Log::warning('updateEmployeeBalance: User not found', [
                    'user_id' => $transaction->user_id,
                    'transaction_id' => $transaction->id
                ]);
                return;
            }

            Log::info('updateEmployeeBalance: Processing', [
                'user_id' => $user->id, 
                'user_role' => $user->role, 
                'transaction_type' => $transaction->type,
                'amount' => $transaction->amount
            ]);

            if ($transaction->type === 'wallet' && $user->role === 'teacher') {
                $teacher = $user->teacher;
                if ($teacher) {
                    $oldBalance = $teacher->wallet;
                    $teacher->wallet += $transaction->amount;
                    $teacher->save();
                    
                    Log::info('updateEmployeeBalance: Teacher wallet updated', [
                        'teacher_id' => $teacher->id,
                        'old_balance' => $oldBalance,
                        'new_balance' => $teacher->wallet,
                        'transaction_id' => $transaction->id
                    ]);
                } else {
                    Log::error('updateEmployeeBalance: Teacher model not found for user', [
                        'user_id' => $user->id, 
                        'email' => $user->email
                    ]);
                }
            } elseif ($transaction->type === 'payment' && $user->role === 'teacher') {
                $teacher = $user->teacher;
                if (!$teacher) {
                    Log::error('updateEmployeeBalance: Teacher model not found for payment', [
                        'user_id' => $user->id, 
                        'email' => $user->email
                    ]);
                    throw new \Exception("Teacher model not found for user ID: {$user->id}");
                }
                
                if ($teacher->wallet < $transaction->amount) {
                    Log::warning('updateEmployeeBalance: Insufficient wallet balance', [
                        'teacher_id' => $teacher->id,
                        'current_balance' => $teacher->wallet,
                        'payment_amount' => $transaction->amount
                    ]);
                    throw new \Exception("Insufficient funds in teacher wallet. Available: {$teacher->wallet}, Required: {$transaction->amount}");
                }
                
                $oldBalance = $teacher->wallet;
                $teacher->wallet -= $transaction->amount;
                $teacher->save();
                
                Log::info('updateEmployeeBalance: Teacher wallet debited for payment', [
                    'teacher_id' => $teacher->id,
                    'old_balance' => $oldBalance,
                    'new_balance' => $teacher->wallet,
                    'transaction_id' => $transaction->id
                ]);
            }
            
            // Handle other transaction types (salary, etc.) if needed
        } catch (\Exception $e) {
            Log::error('updateEmployeeBalance: Exception occurred', [
                'message' => $e->getMessage(),
                'transaction_id' => $transaction->id ?? null,
                'user_id' => $transaction->user_id ?? null
            ]);
            throw $e; // Re-throw to allow caller to handle it
        }
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
    
    // Get all recurring transactions
    $recurringTransactions = Transaction::where('is_recurring', 1)
        ->where(function($query) use ($startDate, $endDate) {
            $query->whereBetween('next_payment_date', [$startDate, $endDate])
                ->orWhereNull('next_payment_date');
        })
        ->get();
    
    // Filter out transactions where the user has already been paid this month
    $paidUserIds = Transaction::where('is_recurring', 0)
        ->whereBetween('payment_date', [$startDate, $endDate])
        ->pluck('user_id')
        ->toArray();
    
    // Only include transactions where the user hasn't been paid this month
    $recurringTransactions = $recurringTransactions->filter(function($transaction) use ($paidUserIds) {
        return !in_array($transaction->user_id, $paidUserIds);
    });
    
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
        
        // Get all recurring transactions
        $recurringTransactions = Transaction::where('is_recurring', 1)
            ->where(function($query) use ($startDate, $endDate) {
                $query->whereBetween('next_payment_date', [$startDate, $endDate])
                    ->orWhereNull('next_payment_date');
            })
            ->get();
        
        if ($recurringTransactions->isEmpty()) {
            return redirect()->back()->with('error', 'No recurring transactions found for this month.');
        }

        // Get users who have already been paid this month
        $paidUserIds = Transaction::where('is_recurring', 0)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->pluck('user_id')
            ->toArray();
        
        // Filter out transactions where the user has already been paid this month
        $unpaidTransactions = $recurringTransactions->filter(function($transaction) use ($paidUserIds) {
            return !in_array($transaction->user_id, $paidUserIds);
        });
        
        if ($unpaidTransactions->isEmpty()) {
            return redirect()->back()->with('success', 'All transactions for this month have already been processed.');
        }

        $count = 0;
        foreach ($unpaidTransactions as $transaction) {
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

        return redirect()->back()->with('success', $count . ' transactions processed successfully.');
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

            // Get the current month date range
            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
            
            // Get users who have already been paid this month
            $paidUserIds = Transaction::where('is_recurring', 0)
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->pluck('user_id')
                ->toArray();

            $count = 0;
            $skipped = 0;
            
            foreach ($transactionIds as $id) {
                $transaction = Transaction::find($id);
                
                if ($transaction && $transaction->is_recurring) {
                    // Skip if user already paid this month
                    if (in_array($transaction->user_id, $paidUserIds)) {
                        $skipped++;
                        continue;
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
                    
                    // Add this user to the paid list to prevent duplicates within this batch
                    $paidUserIds[] = $transaction->user_id;
                    
                    $count++;
                }
            }

            $message = $count . ' transactions processed successfully.';
            if ($skipped > 0) {
                $message .= ' ' . $skipped . ' transactions skipped (already paid this month).';
            }
            
            return redirect()->back()->with('success', $message);
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