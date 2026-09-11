<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Support\OfferPercentages;
use App\Support\TransactionRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TransactionController extends Controller
{
    /**
     * Helper method to format month names in French
     */
    private function formatMonthInFrench($month)
    {
        $frenchMonths = [
            1 => 'Janvier',
            2 => 'Février',
            3 => 'Mars',
            4 => 'Avril',
            5 => 'Mai',
            6 => 'Juin',
            7 => 'Juillet',
            8 => 'Août',
            9 => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Décembre',
        ];

        return $frenchMonths[$month] ?? 'Inconnu';
    }

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

        // Get transactions with their associated user.
        // Was simplePaginate(20000) — a 20,000-row "page" serialized into the Inertia payload
        // on every visit to the payments screen. Backed by transactions(type, payment_date).
        $transactions = Transaction::with('user:id,name,email,role')
            ->orderBy('payment_date', 'desc')
            ->paginate(50)
            ->withQueryString();

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
            'availableYears' => $availableYears,
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
            // Invoice years AND spend years: a year that only holds expenses is a year
            // the accounts still need to show, or its costs become unreachable from
            // the year selector while still existing in the database.
            $invoiceYears = DB::table('invoices')
                ->select(DB::raw('DISTINCT YEAR(billDate) as year'))
                ->whereNull('deleted_at')
                ->pluck('year');

            $transactionYears = DB::table('transactions')
                ->select(DB::raw('DISTINCT YEAR(payment_date) as year'))
                ->whereIn('type', ['salary', 'payment', 'expense'])
                ->whereNotNull('payment_date')
                ->pluck('year');

            $years = $invoiceYears->merge($transactionYears)
                ->map(fn ($year) => (int) $year)
                ->unique()
                ->sortDesc()
                ->values()
                ->toArray();

            // If no years found, use current year
            if (empty($years)) {
                $years = [now()->year];
            }

            return $years;
        } catch (\Exception $e) {
            // Return current year as fallback
            return [now()->year];
        }
    }

    /**
     * Check if a table exists in the database
     *
     * @param  string  $tableName
     * @return bool
     */
    private function tableExists($tableName)
    {
        try {
            // For MySQL
            $result = DB::select(
                'SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = ?', [$tableName]
            );

            return ! empty($result[0]->table_exists);
        } catch (\Exception $e) {
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
        usort($processedEarnings, function ($a, $b) {
            if ($a['year'] != $b['year']) {
                return $b['year'] <=> $a['year']; // Latest year first
            }

            return $b['month'] <=> $a['month']; // Latest month first
        });

        return $processedEarnings;
    }

    /**
     * Get monthly invoice earnings
     *
     * @param  \Carbon\Carbon  $startDate
     * @return \Illuminate\Support\Collection
     */
    private function getMonthlyInvoiceEarnings($startDate)
    {
        try {
            // Get all invoices
            $allInvoices = DB::table('invoices')
                ->select('id', 'billDate', 'amountPaid')
                ->whereNull('deleted_at')
                ->get();

            // Group earnings by year and month
            $groupedEarnings = $this->groupInvoicesByMonth($allInvoices);

            // Convert to collection and sort
            $monthlyEarnings = collect(array_values($groupedEarnings));
            $monthlyEarnings = $monthlyEarnings->sortByDesc(function ($item) {
                return ($item['year'] * 100) + $item['month'];
            })->values();

            return $monthlyEarnings;
        } catch (\Exception $e) {
            return collect([]);
        }
    }

    /**
     * Group invoices by month
     *
     * @param  \Illuminate\Support\Collection  $invoices
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

            if (! isset($groupedEarnings[$key])) {
                $groupedEarnings[$key] = [
                    'year' => $year,
                    'month' => $month,
                    'totalPaid' => 0,
                ];
            }

            $groupedEarnings[$key]['totalPaid'] += (float) $invoice->amountPaid;
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
                'monthName' => $this->formatMonthInFrench($date->month),
                'totalPaid' => 0,
            ];
        }

        return $allMonths;
    }

    /**
     * Fill monthly earnings data into all months array
     *
     * @param  array  $allMonths
     * @param  \Illuminate\Support\Collection  $monthlyEarnings
     * @return array
     */
    private function fillMonthlyEarnings($allMonths, $monthlyEarnings)
    {
        foreach ($monthlyEarnings as $earning) {
            $yearMonth = $earning['year'].'-'.sprintf('%02d', $earning['month']);
            if (isset($allMonths[$yearMonth])) {
                // Make sure to cast to float to avoid string issues
                $allMonths[$yearMonth]['totalPaid'] = (float) ($earning['totalPaid'] ?? 0);
            }
        }

        return $allMonths;
    }

    /**
     * Process monthly earnings with additional metrics
     *
     * @param  array  $allMonths
     * @param  \Illuminate\Support\Collection  $monthlyEarnings
     * @return array
     */
    private function processMonthlyEarnings($allMonths, $monthlyEarnings)
    {
        $processedEarnings = [];

        foreach ($allMonths as $yearMonth => $data) {
            // Find matching entry from manually calculated earnings
            $matchingEarning = $this->findMatchingEarning($monthlyEarnings, $data);

            // Get invoice revenue
            $invoiceRevenue = $matchingEarning ? (float) $matchingEarning['totalPaid'] : (float) $data['totalPaid'];

            // Get monthly enrollment revenue
            $monthlyEnrollmentRevenue = $this->getMonthlyEnrollmentRevenue($data['year'], $data['month']);

            // Get monthly expenses
            $monthlyExpenses = $this->getMonthlyExpenses($data['year'], $data['month']);

            // Calculate totals
            $totalRevenue = $invoiceRevenue + (float) $monthlyEnrollmentRevenue;
            $profit = $totalRevenue - (float) $monthlyExpenses;

            $processedEarnings[] = [
                'year' => $data['year'],
                'month' => $data['month'],
                'monthName' => $data['monthName'],
                'totalRevenue' => $totalRevenue,
                'totalExpenses' => (float) $monthlyExpenses,
                'profit' => $profit,
                'yearMonth' => $yearMonth,
            ];
        }

        return $processedEarnings;
    }

    /**
     * Find matching earning from monthly earnings
     *
     * @param  \Illuminate\Support\Collection  $monthlyEarnings
     * @param  array  $data
     * @return array|null
     */
    private function findMatchingEarning($monthlyEarnings, $data)
    {
        return $monthlyEarnings->first(function ($item) use ($data) {
            return $item['year'] == $data['year'] && $item['month'] == $data['month'];
        });
    }

    /**
     * Get monthly enrollment revenue
     *
     * @param  int  $year
     * @param  int  $month
     * @return float
     */
    private function getMonthlyEnrollmentRevenue($year, $month)
    {
        $monthlyEnrollmentRevenue = 0;

        if ($this->tableExists('enrollments')) {
            try {
                $monthlyEnrollmentRevenue = DB::table('enrollments')
                    ->join('courses', 'enrollments.course_id', '=', 'courses.id')
                    ->whereRaw('YEAR(enrollments.created_at) = ?', [$year])
                    ->whereRaw('MONTH(enrollments.created_at) = ?', [$month])
                    ->whereNull('enrollments.deleted_at')
                    ->sum(DB::raw('CAST(courses.price AS DECIMAL(10,2))'));
            } catch (\Exception $e) {
                $monthlyEnrollmentRevenue = 0;
            }
        }

        return $monthlyEnrollmentRevenue;
    }

    /**
     * Get monthly expenses
     *
     * @param  int  $year
     * @param  int  $month
     * @return float
     */
    private function getMonthlyExpenses($year, $month)
    {
        $monthlyExpenses = 0;

        try {
            // Transaction::query() — NOT DB::table(). The inMonth() scope lives on the
            // model and never on the query builder; on DB::table() it throws
            // BadMethodCallException, this catch silently turned it into 0, and every
            // caller rendered "Dépenses totales 0,00 DH" next to a Résumé mensuel that
            // counted the same transactions.
            $monthlyExpenses = Transaction::query()
                ->where(function ($query) {
                    $query->where('type', 'salary')
                        ->orWhere('type', 'payment')
                        ->orWhere('type', 'expense');
                })
                ->inMonth($year, $month)
                ->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
        } catch (\Exception $e) {
            $monthlyExpenses = 0;
        }

        return $monthlyExpenses;
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

        $processedEarnings = [];

        // Get the actual invoice data with multi-month distribution
        $invoiceData = DB::table('invoices')
            ->select('id', 'billDate', 'totalAmount', 'amountPaid', 'selected_months', 'months', 'includePartialMonth', 'partialMonthAmount')
            ->whereNull('deleted_at')
            ->get();

        // Group invoices by month with proper distribution
        $monthlyData = [];
        foreach ($invoiceData as $invoice) {
            $selectedMonths = json_decode($invoice->selected_months, true);

            // Handle double-encoded JSON (if selectedMonths is a string, decode it again)
            if (is_string($selectedMonths)) {
                $selectedMonths = json_decode($selectedMonths, true);
            }

            // Ensure selectedMonths is an array
            if (! is_array($selectedMonths)) {
                $selectedMonths = [];
            }

            if (empty($selectedMonths)) {
                // Fallback: use billDate month if no selected_months
                $date = Carbon::parse($invoice->billDate);
                $selectedMonths = [$date->format('Y-m')];
            }

            /*
             * Partial-month invoices: the billDate's month carries the partial amount, so
             * it joins the distribution. This mirrors getFilteredMonthlyStats exactly —
             * the two panels used to attribute the same invoice to different months and
             * the "Résumé mensuel" disagreed with the cards beside it.
             */
            $billMonth = Carbon::parse($invoice->billDate)->format('Y-m');
            if ((bool) $invoice->includePartialMonth && (float) $invoice->partialMonthAmount > 0
                && ! in_array($billMonth, $selectedMonths, true)) {
                array_unshift($selectedMonths, $billMonth);
            }

            // Distribute amount across selected months
            $amountPerMonth = count($selectedMonths) > 0 ? (float) $invoice->amountPaid / count($selectedMonths) : (float) $invoice->amountPaid;

            foreach ($selectedMonths as $monthYear) {
                if (empty($monthYear)) {
                    continue;
                }

                $date = Carbon::createFromFormat('Y-m', $monthYear);
                $year = $date->year;
                $month = $date->month;

                $key = "$year-$month";
                if (! isset($monthlyData[$key])) {
                    $monthlyData[$key] = [
                        'year' => $year,
                        'month' => $month,
                        'totalRevenue' => 0,
                    ];
                }

                $monthlyData[$key]['totalRevenue'] += $amountPerMonth;
            }
        }

        /*
         * Months that carry spend must exist in the map even when no invoice lands in
         * them.
         *
         * The first version queried expenses only for months an invoice had already
         * keyed, and the 12-month backfill below hardcodes totalExpenses = 0 — so a
         * month with no revenue summed, charted and card-ed as zero spend while the
         * Résumé mensuel, which filters transactions directly, told the truth. That is
         * the "Dépenses totales 0,00 DH" the screen showed next to a month holding
         * 600 DH of recorded expenses.
         */
        $expenseMonths = DB::table('transactions')
            ->whereIn('type', ['salary', 'payment', 'expense'])
            ->whereNotNull('payment_date')
            ->selectRaw('DISTINCT YEAR(payment_date) as y, MONTH(payment_date) as m')
            ->get();

        foreach ($expenseMonths as $expenseMonth) {
            $key = $expenseMonth->y.'-'.$expenseMonth->m;

            if (! isset($monthlyData[$key])) {
                $monthlyData[$key] = [
                    'year' => (int) $expenseMonth->y,
                    'month' => (int) $expenseMonth->m,
                    'totalRevenue' => 0,
                ];
            }
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

            $monthlyData[$key]['totalExpenses'] = (float) $expenses;
            $monthlyData[$key]['profit'] = $monthlyData[$key]['totalRevenue'] - $monthlyData[$key]['totalExpenses'];
            $monthlyData[$key]['monthName'] = $this->formatMonthInFrench($data['month']);
        }

        /*
         * Backfill the last 12 months so the chart has no holes — then emit EVERY month
         * in the map. The previous code pushed only the 12 most recent months, so a year
         * offered by the dropdown showed just its tail; the yearly cards summed an
         * incomplete list and called it "total".
         */
        for ($i = 0; $i < 12; $i++) {
            $date = now()->subMonths($i);
            $year = $date->year;
            $month = $date->month;
            $key = "$year-$month";

            if (! isset($monthlyData[$key])) {
                $monthlyData[$key] = [
                    'year' => $year,
                    'month' => $month,
                    'monthName' => $this->formatMonthInFrench($month),
                    'totalRevenue' => 0,
                    'totalExpenses' => 0,
                    'profit' => 0,
                ];
            }
        }

        foreach ($monthlyData as $key => $data) {
            $monthlyData[$key]['yearMonth'] = $key;
            $processedEarnings[] = $monthlyData[$key];
        }

        // Sort by year and month (descending)
        usort($processedEarnings, function ($a, $b) {
            if ($a['year'] != $b['year']) {
                return $b['year'] <=> $a['year']; // Latest year first
            }

            return $b['month'] <=> $a['month']; // Latest month first
        });

        // Include available years in the response
        return response()->json([
            'earnings' => $processedEarnings,
            'availableYears' => $availableYears,
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
            'message' => 'Invoice data debug',
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
                    DB::raw('YEAR(billDate) as year'),
                    DB::raw('SUM(CAST(amountPaid AS DECIMAL(10,2))) as yearTotal')
                )
                ->whereNull('deleted_at')
                ->groupBy('year')
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
        if (! $earliestYear) {
            $earliestYear = now()->year - 1;
        }

        // Set start date to the beginning of the earliest year
        $startDate = Carbon::createFromDate($earliestYear, 1, 1);

        // Get all paid amounts from invoices, distributed across selected months
        try {
            $invoices = DB::table('invoices')
                ->select('id', 'billDate', 'amountPaid', 'selected_months', 'months')
                ->whereNull('deleted_at')
                ->where('billDate', '>=', $startDate)
                ->get();

            // Distribute payments across selected months
            $monthlyEarnings = [];
            foreach ($invoices as $invoice) {
                $amountPaid = (float) $invoice->amountPaid;
                $selectedMonths = json_decode($invoice->selected_months, true) ?? [];

                // Handle double-encoded JSON (if selectedMonths is a string, decode it again)
                if (is_string($selectedMonths)) {
                    $selectedMonths = json_decode($selectedMonths, true) ?? [];
                }

                // Ensure selectedMonths is an array
                if (! is_array($selectedMonths)) {
                    $selectedMonths = [];
                }

                if (empty($selectedMonths)) {
                    // Fallback: use billDate month if no selected_months
                    $billDate = \Carbon\Carbon::parse($invoice->billDate);
                    $selectedMonths = [$billDate->format('Y-m')];
                }

                // Distribute amount across selected months
                $monthsCount = max(count($selectedMonths), 1);
                $amountPerMonth = $amountPaid / $monthsCount;

                foreach ($selectedMonths as $monthYear) {
                    if (empty($monthYear)) {
                        continue;
                    }

                    $date = \Carbon\Carbon::createFromFormat('Y-m', $monthYear);
                    $year = $date->year;
                    $month = $date->month;
                    $key = $year.'-'.$month;

                    if (! isset($monthlyEarnings[$key])) {
                        $monthlyEarnings[$key] = [
                            'year' => $year,
                            'month' => $month,
                            'totalPaid' => 0,
                        ];
                    }

                    $monthlyEarnings[$key]['totalPaid'] += $amountPerMonth;
                }
            }

            // Convert to collection and sort
            $monthlyEarnings = collect(array_values($monthlyEarnings))
                ->sortByDesc(function ($item) {
                    return ($item['year'] * 100) + $item['month'];
                })
                ->values();

        } catch (\Exception $e) {
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
                'monthName' => $this->formatMonthInFrench($date->month),
                'totalPaid' => 0,
            ];
        }

        // Fill in actual earnings data
        foreach ($monthlyEarnings as $earning) {
            $yearMonth = $earning['year'].'-'.sprintf('%02d', $earning['month']);
            if (isset($allMonths[$yearMonth])) {
                // Make sure to cast to float to avoid string issues
                $allMonths[$yearMonth]['totalPaid'] = (float) ($earning['totalPaid'] ?? 0);
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
                        ->whereRaw('YEAR(enrollments.created_at) = ?', [$data['year']])
                        ->whereRaw('MONTH(enrollments.created_at) = ?', [$data['month']])
                        ->whereNull('enrollments.deleted_at')
                        ->sum(DB::raw('CAST(courses.price AS DECIMAL(10,2))'));
                } catch (\Exception $e) {
                    $monthlyEnrollmentRevenue = 0;
                }
            }

            // Get existing monthly expenses
            $monthlyExpenses = 0;
            try {
                // Transaction::query() — NOT DB::table(). The inMonth() scope lives on the
                // model and never on the query builder: this used to throw
                // BadMethodCallException, the catch below swallowed it into 0, and every
                // month on the payments screen reported "Dépenses totales 0,00 DH" while the
                // Résumé mensuel next to it counted the same transactions.
                $monthlyExpenses = Transaction::query()
                    ->where(function ($query) {
                        $query->where('type', 'salary')
                            ->orWhere('type', 'payment')
                            ->orWhere('type', 'expense');
                    })
                    ->inMonth($data['year'], $data['month'])
                    ->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
            } catch (\Exception $e) {
                $monthlyExpenses = 0;
            }

            // Calculate total revenue (invoices + enrollments)
            $invoiceRevenue = (float) $data['totalPaid'];
            $totalRevenue = $invoiceRevenue + (float) $monthlyEnrollmentRevenue;

            // Calculate profit
            $profit = $totalRevenue - (float) $monthlyExpenses;

            $processedEarnings[] = [
                'year' => $data['year'],
                'month' => $data['month'],
                'monthName' => $data['monthName'],
                'totalRevenue' => $totalRevenue,
                'totalExpenses' => (float) $monthlyExpenses,
                'profit' => $profit,
                'yearMonth' => $yearMonth,
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
            'availableYears' => $availableYears,
        ];
    }

    /**
     * Display a listing of transactions.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // NOTE: this method used to compute $transactions (a paginated query), $totalAmount
        // (a SUM over the year) and $availableYears (a DISTINCT YEAR scan) — and then throw all
        // three away, because $data = $this->getCommonData() below overwrites everything that
        // is actually returned. Three queries per page load for nothing.
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
    public function create(Request $request)
    {
        $data = $this->getFormData('create', null, $this->formDate($request));

        // "Payer" on the employee list arrives as ?user_id=. It used to arrive as a whole
        // prefilled form in the query string — type, amount, description — which create()
        // read none of, so the button opened a blank form.
        $data['preselectedUserId'] = $request->filled('user_id')
            ? (int) $request->input('user_id')
            : null;

        return Inertia::render('Menu/PaymentsPage', $data);
    }

    /**
     * Parse the ?on= date the form sends when its payment date moves to another month.
     *
     * An assistant's available balance is their salary less what they have been paid IN
     * THAT MONTH, so it is not a property of the person — it changes with the date on the
     * form. Backdating a payment to a month they were already paid for has to show the
     * remainder for that month, not for today.
     */
    private function formDate(Request $request): Carbon
    {
        try {
            return $request->filled('on')
                ? Carbon::parse($request->input('on'))
                : Carbon::now();
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    /**
     * The props a form view needs — and nothing else.
     *
     * create() and edit() used to call getCommonData(), which loads every user, a page of
     * 50 transactions, and calculateAdminEarningsForComparison(): a loop over every month
     * since the earliest invoice in the system, running two aggregate queries per month.
     * Opening the "new transaction" form paid for a full financial dashboard that the form
     * view does not render — PaymentsPage swaps to `activeView === "form"` and the list,
     * the analytics and the earnings section are all unmounted.
     *
     * What a form actually needs is the staff it can pay, and what each of them is owed.
     */
    private function getFormData(string $formType, ?Transaction $transaction = null, ?Carbon $date = null): array
    {
        // The date drives the assistant salary cap, so the balances have to be computed
        // against the date the form will open on, not against today.
        $date ??= $transaction?->payment_date
            ? Carbon::parse($transaction->payment_date)
            : Carbon::now();

        $staff = User::with(['teacher', 'assistant'])
            ->whereIn('role', TransactionRules::PAYABLE_ROLES)
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => TransactionRules::summarise($user, $date))
            ->values()
            ->all();

        return [
            'formType' => $formType,
            'transaction' => $transaction,
            'staff' => $staff,
            'expenseCategories' => self::EXPENSE_CATEGORIES,
            'frequencies' => self::FREQUENCY_LABELS,
            // PaymentsPage renders a paginator and an earnings panel below the form area.
            // Empty shells rather than real data: nothing on a form view reads them, and
            // computing them is the expensive half of this request.
            'transactions' => ['data' => [], 'links' => [], 'total' => 0],
            'adminEarnings' => null,
        ];
    }

    /**
     * Expense categories, defined server-side.
     *
     * They were hardcoded in TransactionDetails.jsx, which meant nothing on the server
     * could validate against them — and `category` was never persisted anyway. Now the
     * form renders this list and the validator checks against it.
     */
    public const EXPENSE_CATEGORIES = [
        'classroom' => 'Matériel de classe',
        'office' => 'Fournitures de bureau',
        'sports' => 'Équipement sportif',
        'technology' => 'Technologie',
        'library' => 'Ressources de bibliothèque',
        'internet' => 'Internet / WiFi',
        'utilities' => 'Eau, électricité',
        'maintenance' => 'Maintenance',
        'travel' => 'Sorties scolaires',
        'training' => 'Formation du personnel',
        'software' => 'Logiciels',
        'rent' => 'Loyer',
        'other' => 'Autre',
    ];

    /** Labels for Transaction::FREQUENCIES — the one accepted vocabulary. */
    public const FREQUENCY_LABELS = [
        'weekly' => 'Chaque semaine',
        'monthly' => 'Chaque mois',
        'quarterly' => 'Tous les 3 mois',
        'semiannually' => 'Tous les 6 mois',
        'yearly' => 'Chaque année',
    ];

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            // Logs the fields, not $request->all(). The old call wrote the entire request
            // body — named staff, amounts, free-text descriptions — into the application
            // log on every single transaction, where it is kept far longer than anyone
            // reviewing payroll access expects.
            Log::info('Transaction store request', [
                'type' => $request->input('type'),
                'user_id' => $request->input('user_id'),
                'amount' => $request->input('amount'),
            ]);

            $validated = $this->validateTransactionData($request);
            $validated = $this->applyPaymentRules($validated);

            // The transaction row and the wallet movement are one unit of work.
            //
            // This used to save the row, then move the wallet, then "compensate" for a
            // failed wallet move by deleting the row again — a hand-rolled rollback that
            // could itself fail and leave the two out of step. A real transaction rolls
            // back both or neither.
            $transaction = null;

            DB::transaction(function () use ($validated, &$transaction) {
                $transaction = new Transaction($validated);
                $transaction->save();

                if (in_array($transaction->type, Transaction::BALANCE_TYPES, true)) {
                    $this->updateEmployeeBalance($transaction);
                }
            });

            Log::info('Transaction created successfully', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
            ]);

            return redirect()->route('transactions.index')
                ->with('success', $this->successMessage($transaction));

        } catch (ValidationException $e) {
            // Business rejections (wallet too small, salary already paid) arrive here as
            // well as format errors, because App\Support\TransactionRules throws them
            // attached to the field they concern. withInput() matters: the form is long
            // enough that losing it is its own reason not to use the screen.
            Log::warning('Transaction store validation failed', ['errors' => $e->errors()]);

            return back()->withErrors($e->errors())->withInput();

        } catch (\Throwable $e) {
            // \Throwable, not \Exception: a TypeError or a DB deadlock here used to escape
            // to the generic 500 page, which tells the person doing payroll nothing about
            // whether the money moved.
            Log::error('Transaction store exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->with('error', "La transaction n'a pas pu être enregistrée. Aucun montant n'a été déplacé. Détail : ".$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Resolve and check everything about WHO is being paid, before anything is written.
     *
     * Replaces the two overlapping blocks store() used to run — one that computed `rest`
     * and one that re-checked the same wallet three ways — and is now shared with update()
     * so an edit cannot do what a create refuses.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function applyPaymentRules(array $validated, ?Transaction $existing = null): array
    {
        $validated['is_recurring'] = ! empty($validated['is_recurring']) ? 1 : 0;

        if (empty($validated['payment_date'])) {
            $validated['payment_date'] = now();
        }

        $paymentDate = Carbon::parse($validated['payment_date']);

        if (! $validated['is_recurring']) {
            // Leaving these set on a non-recurring row makes it show up in the recurring
            // list with a due date, where somebody will eventually process it.
            $validated['frequency'] = null;
            $validated['next_payment_date'] = null;
        }

        if ($validated['type'] === Transaction::TYPE_EXPENSE) {
            $validated['category'] = $validated['category'] ?: 'other';
            $validated['rest'] = null;
            // An expense has no payee. user_id stays as sent so the page keeps a record of
            // who entered it, but user_name is cleared: it belongs to the payee, and
            // showing the admin's name in the "paid to" column read as a payment to them.
            $validated['user_name'] = null;

            return $validated;
        }

        // Every other type pays a person, so one must be chosen.
        $user = ! empty($validated['user_id']) ? User::find($validated['user_id']) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'Veuillez choisir la personne à payer.',
            ]);
        }

        $validated['category'] = null;
        $validated['user_name'] = $user->name;

        // A wallet top-up ADDS money, so there is no balance to run out of. It is also no
        // longer reachable from this form — top-ups go through the teacher's own wallet
        // panel, which requires a reason — but the type is still accepted for the batch
        // and recurring paths that predate it.
        if ($validated['type'] === Transaction::TYPE_WALLET) {
            if ($user->role !== 'teacher') {
                throw ValidationException::withMessages([
                    'user_id' => 'Seul un enseignant possède un portefeuille.',
                ]);
            }

            $validated['rest'] = null;

            return $validated;
        }

        TransactionRules::assertPayable($user, (float) $validated['amount'], $paymentDate, $existing?->id);

        // The type follows the role — it is never taken from the request. The form used to
        // post a type chosen in a dropdown and then correct it in JavaScript on submit, so
        // a stale value could reach the server and record a teacher's payout as a salary,
        // which bypasses the wallet entirely.
        $validated['type'] = TransactionRules::typeFor($user);
        $validated['rest'] = TransactionRules::restAfter($user, (float) $validated['amount'], $paymentDate, $existing?->id);

        return $validated;
    }

    /** What actually happened, in one sentence, in French. */
    private function successMessage(Transaction $transaction): string
    {
        $amount = TransactionRules::money((float) $transaction->amount);
        $who = $transaction->user_name ?: 'le personnel';

        return match ($transaction->type) {
            Transaction::TYPE_PAYMENT => "{$amount} versés à {$who}, déduits de son portefeuille.",
            Transaction::TYPE_SALARY => "{$amount} versés à {$who} au titre de son salaire.",
            Transaction::TYPE_WALLET => "{$amount} ajoutés au portefeuille de {$who}.",
            default => "Dépense de {$amount} enregistrée.",
        };
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
    public function edit(Request $request, $id)
    {
        $transaction = Transaction::with('user')->findOrFail($id);

        return Inertia::render('Menu/PaymentsPage', $this->getFormData(
            'edit',
            $transaction,
            $request->filled('on') ? $this->formDate($request) : null,
        ));
    }

    /**
     * Update the specified transaction in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        // Captured BEFORE the row is touched: the wallet revert below needs what the row
        // used to say, and $transaction->update() overwrites it in place.
        $oldType = $transaction->type;
        $oldAmount = (float) $transaction->amount;
        $oldUserId = $transaction->user_id;

        try {
            $validated = $this->validateTransactionData($request);

            // Same rules as a create, and for the same reason: an edit moves exactly as
            // much money as a create does. update() used to check the teacher's wallet
            // only when the amount went up, and never checked an assistant's salary cap at
            // all — so an assistant already paid in full could be edited to any figure.
            $validated = $this->applyPaymentRules($validated, $transaction);

            $balanceChanged = $oldType !== $validated['type']
                || abs($oldAmount - (float) $validated['amount']) > 0.001
                || $oldUserId !== ($validated['user_id'] ?? null);

            // Atomic: the row update, the revert of the old wallet effect and the apply of
            // the new one are three writes that must not be able to land partially.
            // Without this, a failure between the revert and the apply left a teacher
            // permanently debited.
            DB::transaction(function () use ($transaction, $validated, $oldType, $oldAmount, $oldUserId, $balanceChanged) {
                $transaction->update($validated);

                if (! $balanceChanged) {
                    return;
                }

                // The revert runs whenever the OLD row moved a balance, even if the new
                // one does not. Retyping a teacher payout as an expense used to skip this
                // branch entirely — the outer `if` required the NEW type to be a balance
                // type — and the money stayed out of the wallet with nothing recording it.
                if (in_array($oldType, Transaction::BALANCE_TYPES, true)) {
                    $this->revertEmployeeBalance([
                        'type' => $oldType,
                        'user_id' => $oldUserId,
                        'amount' => $oldAmount,
                    ]);
                }

                if (in_array($transaction->type, Transaction::BALANCE_TYPES, true)) {
                    $this->updateEmployeeBalance($transaction);
                }
            });

            return redirect()->route('transactions.index')
                ->with('success', $this->successMessage($transaction->refresh()));

        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();

        } catch (\Throwable $e) {
            // There was no catch here at all. updateEmployeeBalance() throws on an
            // insufficient wallet, so raising the amount on an old payout past the current
            // balance produced a raw 500 page rather than a message about the balance.
            Log::error('Transaction update exception', [
                'transaction_id' => $transaction->id,
                'message' => $e->getMessage(),
            ]);

            return back()
                ->with('error', "La transaction n'a pas pu être modifiée. Rien n'a changé. Détail : ".$e->getMessage())
                ->withInput();
        }
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

        try {
            // Atomic: reverting the wallet and deleting the row must land together, or a
            // failed delete leaves the teacher debited for a transaction that still exists.
            DB::transaction(function () use ($transaction) {
                if (in_array($transaction->type, Transaction::BALANCE_TYPES, true)) {
                    $this->revertEmployeeBalance($transaction);
                }

                $transaction->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Transaction delete exception', [
                'transaction_id' => $transaction->id,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', "La transaction n'a pas pu être supprimée. Détail : ".$e->getMessage());
        }

        // Deleting a teacher payout puts the money BACK in the wallet, which is the
        // opposite of what "supprimer" sounds like. Worth saying, because the next thing
        // the admin sees is a wallet balance that went up.
        $restored = $transaction->type === Transaction::TYPE_PAYMENT
            ? ' '.TransactionRules::money((float) $transaction->amount).' ont été rendus au portefeuille de '.($transaction->user_name ?: "l'enseignant").'.'
            : '';

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction supprimée.'.$restored);
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
     * @return \Illuminate\Http\Response
     */
    public function batchPayEmployees(Request $request)
    {
        $validated = $request->validate([
            'role' => 'required|in:teacher,assistant,all',
            'payment_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'is_recurring' => 'nullable|boolean',
            'frequency' => 'nullable|required_if:is_recurring,1,true|in:'.implode(',', Transaction::FREQUENCIES),
            'next_payment_date' => 'nullable|required_if:is_recurring,1,true|date|after_or_equal:payment_date',
        ]);

        $paymentDate = Carbon::parse($validated['payment_date']);

        $candidates = User::with(['teacher', 'assistant'])
            ->when(
                $validated['role'] !== 'all',
                fn ($q) => $q->where('role', $validated['role']),
                fn ($q) => $q->whereIn('role', TransactionRules::PAYABLE_ROLES)
            )
            ->get();

        // Eligibility is now one question — "is anything owed?" — asked of the same helper
        // that the single-payment form and the recurring runner use. This method used to
        // exclude anyone with ANY payment in the month, so an assistant paid half their
        // salary was dropped from the batch entirely rather than being paid the other half;
        // availableFor() nets that off and pays exactly the remainder.
        $eligible = $candidates->filter(
            fn ($user) => TransactionRules::availableFor($user, $paymentDate) > 0
        );

        if ($eligible->isEmpty()) {
            return redirect()->route('transactions.index')->with(
                'warning',
                'Personne à payer pour '.$paymentDate->format('m/Y')
                    .' : les '.$candidates->count().' membres du personnel concernés sont déjà réglés ou n\'ont rien en attente.'
            );
        }

        $result = $this->processBatchPayment($eligible, $validated);

        return redirect()->route('transactions.index')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
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
        $alreadyPaidUserIds = Transaction::inMonth($year, $month)
            ->where(function ($query) {
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
        $eligibleUsers = $allUsers->filter(function ($user) use ($alreadyPaidUserIds) {
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
        $paidUsers = $allUsers->filter(function ($user) use ($alreadyPaidUserIds) {
            return in_array($user->id, $alreadyPaidUserIds);
        });

        // Zero wallet teachers (for reference display)
        $zeroWalletTeachers = $allUsers->filter(function ($user) use ($alreadyPaidUserIds) {
            return $user->role === 'teacher' &&
                   (! $user->teacher || $user->teacher->wallet <= 0) &&
                   ! in_array($user->id, $alreadyPaidUserIds);
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
            'paidUsers' => $paidUsers->values()->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ];
            }),
            'zeroWalletTeachers' => $zeroWalletTeachers->values()->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'wallet' => $user->teacher ? $user->teacher->wallet : 0,
                ];
            }),
        ]);
    }

    /**
     * Pay a group of staff in one go, each for whatever they are currently owed.
     *
     * TWO BUGS THIS REPLACES
     * ----------------------
     * 1. `$alreadyPaid` was both a counter and a sum of money. It was incremented per
     *    skipped employee, then REASSIGNED inside the assistant branch to
     *    `Transaction::...->sum('amount')`. After the first assistant who had been paid
     *    anything, the closing summary read that dirham total as a headcount: a run that
     *    skipped two people and touched an assistant already paid 4 500 DH reported
     *    "4500 employees were skipped because they were already paid this month."
     *
     * 2. One `DB::beginTransaction()` wrapped the entire loop, so a single failure — an
     *    assistant with a missing staff record, a wallet that emptied mid-run — rolled
     *    back everybody, including the payments that had succeeded. The admin saw an error
     *    and no payments, with nothing saying which employee caused it.
     *
     * Each employee is now their own transaction, and every skip carries a reason.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $users
     * @param  array  $data
     * @return array{success: bool, processed: int, message: string}
     */
    private function processBatchPayment($users, $data)
    {
        $paymentDate = Carbon::parse($data['payment_date']);
        $processed = 0;
        $totalPaid = 0.0;
        $skipped = [];

        foreach ($users as $user) {
            $amount = TransactionRules::availableFor($user, $paymentDate);

            if ($amount <= 0) {
                $skipped[] = $user->name.($user->role === 'teacher'
                    ? ' (portefeuille vide)'
                    : ' (salaire déjà versé)');

                continue;
            }

            try {
                DB::transaction(function () use ($user, $amount, $data, $paymentDate, &$processed, &$totalPaid) {
                    // Re-checked inside the transaction. The eligibility list was built
                    // before the run, and a manual payment made in another tab between the
                    // two would otherwise be paid twice.
                    TransactionRules::assertPayable($user, $amount, $paymentDate);

                    $transaction = Transaction::create([
                        'type' => TransactionRules::typeFor($user),
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'amount' => $amount,
                        'rest' => TransactionRules::restAfter($user, $amount, $paymentDate),
                        'description' => $data['description']
                            ?: 'Paiement groupé — '.$paymentDate->format('m/Y'),
                        'payment_date' => $data['payment_date'],
                        'is_recurring' => ! empty($data['is_recurring']) ? 1 : 0,
                        'frequency' => $data['frequency'] ?? null,
                        'next_payment_date' => $data['next_payment_date'] ?? null,
                    ]);

                    $this->updateEmployeeBalance($transaction);

                    $processed++;
                    $totalPaid += $amount;
                });
            } catch (ValidationException $e) {
                $skipped[] = $user->name.' : '.implode(' ', array_merge(...array_values($e->errors())));
            } catch (\Throwable $e) {
                Log::error('Batch payment failed for one employee', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                $skipped[] = $user->name.' : '.$e->getMessage();
            }
        }

        $message = $processed > 0
            ? "{$processed} paiement(s) effectué(s), ".TransactionRules::money($totalPaid).' au total.'
            : 'Aucun paiement effectué.';

        if ($skipped !== []) {
            $message .= ' Non payés : '.implode(' | ', $skipped);
        }

        return [
            // A run that paid nobody is not a success, even when nothing threw.
            'success' => $processed > 0,
            'processed' => $processed,
            'message' => $message,
        ];
    }

    /**
     * Turn recurring templates into real, paid transactions.
     *
     * THE BUG THIS REPLACES
     * ---------------------
     * There were five copies of this loop — one here and one inside each of the four
     * routed process*Recurring* methods — and the four routed ones never touched a wallet.
     * They created a row of `type => 'payment'` against a teacher, recorded it in the
     * pivot, advanced the schedule, and reported success, while the teacher's wallet was
     * never debited. The payout existed on the payments screen and in the month's expense
     * total; the money was still sitting in the wallet, available to be paid out a second
     * time by hand. Only the copy in this method updated a balance, and only for
     * `salary`/`wallet` — never `payment`, the type every teacher payout actually uses.
     *
     * Everything now runs through here: one row, one wallet movement, one pivot entry, one
     * schedule advance, per template, inside one transaction.
     *
     * A template that cannot be paid — an empty wallet, a teacher whose staff record has
     * gone missing — is SKIPPED with a reason rather than aborting the run. One
     * unpayable teacher used to take the whole month's batch down with it, and because the
     * loop was not transactional, whatever had already been written stayed written.
     *
     * @param  iterable<Transaction>  $templates
     * @return array{processed: int, skipped: array<int, string>}
     */
    private function runRecurring($templates): array
    {
        $processed = 0;
        $skipped = [];

        foreach ($templates as $template) {
            $dueDate = $template->next_payment_date
                ? Carbon::parse($template->next_payment_date)
                : Carbon::now();

            try {
                DB::transaction(function () use ($template, $dueDate, &$processed) {
                    $payee = $template->user_id ? User::find($template->user_id) : null;
                    $amount = (float) $template->amount;
                    $type = $template->type;

                    if ($payee && in_array($type, [Transaction::TYPE_SALARY, Transaction::TYPE_PAYMENT], true)) {
                        // The same check a manual payment gets. Without it a recurring
                        // teacher payout drains a wallet that no longer holds the amount,
                        // and TeacherWalletService::debit() clamps at zero — so the row
                        // would claim more than the ledger actually moved.
                        TransactionRules::assertPayable($payee, $amount, $dueDate);
                        $type = TransactionRules::typeFor($payee);
                    }

                    $paid = Transaction::create([
                        'type' => $type,
                        'category' => $template->category,
                        'user_id' => $template->user_id,
                        'user_name' => $payee?->name ?? $template->user_name,
                        'amount' => $amount,
                        'rest' => $payee && $type !== Transaction::TYPE_EXPENSE
                            ? TransactionRules::restAfter($payee, $amount, $dueDate)
                            : null,
                        'description' => trim(($template->description ?: 'Paiement récurrent')
                            .' (récurrence n°'.$template->id.')'),
                        'payment_date' => $dueDate,
                        // The child is a one-off. Leaving is_recurring set made it a
                        // template in its own right, so the next run picked it up too and
                        // the schedule multiplied every month.
                        'is_recurring' => 0,
                        'frequency' => null,
                        'next_payment_date' => null,
                    ]);

                    if (in_array($paid->type, Transaction::BALANCE_TYPES, true)) {
                        $this->updateEmployeeBalance($paid);
                    }

                    \App\Models\RecurringTransactionPayment::create([
                        'recurring_transaction_id' => $template->id,
                        'transaction_id' => $paid->id,
                        'period' => $dueDate->format('Y-m'),
                    ]);

                    // Advance from the date that was DUE, not from today. Advancing from
                    // now() meant a run that happened three days late pushed every
                    // subsequent payment three days later, month after month.
                    $template->next_payment_date = $template->nextDateAfter($dueDate);
                    $template->save();

                    $processed++;
                });
            } catch (ValidationException $e) {
                $skipped[] = ($template->user_name ?: 'Récurrence n°'.$template->id).' : '
                    .implode(' ', array_merge(...array_values($e->errors())));
            } catch (\Throwable $e) {
                Log::error('Recurring transaction failed', [
                    'recurring_transaction_id' => $template->id,
                    'message' => $e->getMessage(),
                ]);

                $skipped[] = ($template->user_name ?: 'Récurrence n°'.$template->id).' : '.$e->getMessage();
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * One sentence describing what a recurring run did, including what it could not do.
     *
     * @param  array{processed: int, skipped: array<int, string>}  $result
     */
    private function recurringOutcome(array $result): array
    {
        $processed = $result['processed'];
        $skipped = $result['skipped'];

        $message = $processed === 1
            ? '1 paiement récurrent effectué.'
            : "{$processed} paiements récurrents effectués.";

        if ($skipped === []) {
            return ['key' => $processed > 0 ? 'success' : 'warning', 'message' => $processed > 0
                ? $message
                : 'Aucun paiement récurrent à effectuer.'];
        }

        // The reasons are listed, not counted. "3 skipped" tells the admin something is
        // wrong but not which teacher to look at, so nothing gets fixed.
        return [
            'key' => $processed > 0 ? 'warning' : 'error',
            'message' => $message.' Non effectués : '.implode(' | ', $skipped),
        ];
    }

    // NOTE: calculateNextPaymentDate() and updateNextPaymentDate() lived here. They were
    // the second and third mappings from a frequency to a date, and neither agreed with
    // the other or with the list the form offered — see Transaction::FREQUENCIES. The one
    // mapping is now Transaction::nextDateAfter().

    /**
     * Validate transaction data
     *
     * @return array
     */
    private function validateTransactionData(Request $request)
    {
        $frequencies = implode(',', Transaction::FREQUENCIES);
        $categories = implode(',', array_keys(self::EXPENSE_CATEGORIES));

        // ONE validator for create and update. They used to differ: store() accepted
        // weekly/monthly/quarterly/yearly and a 255-char description, update() accepted
        // monthly/yearly/custom and 500 chars. So a transaction saved as "quarterly" with
        // a 400-character note could not be edited afterwards without silently failing
        // validation — and the resulting error never rendered on the payments page.
        $rules = [
            // No TYPE_WALLET here on purpose: wallet top-ups are created only
            // through the teacher wallet panel (admin-only, reason required) or
            // wallet:adjust (console, dry-run default, idempotent). Accepting it
            // on this form let any assistant mint ledger money with a crafted
            // POST — uncapped, note-free and idempotency-exempt. The batch and
            // recurring paths force their own types and never read this rule.
            'type' => 'required|in:'.implode(',', [
                Transaction::TYPE_SALARY,
                Transaction::TYPE_PAYMENT,
                Transaction::TYPE_EXPENSE,
            ]),
            'user_id' => 'nullable|exists:users,id',
            'category' => 'nullable|required_if:type,expense|in:'.$categories,
            'custom_category' => 'nullable|string|max:100',
            // min:0.01, not min:0. A zero-dirham transaction moves nothing, reconciles as
            // a payment that never happened, and blocks the batch run for that month
            // because the payee now counts as already paid.
            'amount' => 'required|numeric|min:0.01|max:9999999.99',
            'description' => 'nullable|string|max:500',
            'payment_date' => 'required|date',
            'is_recurring' => 'nullable|boolean',
            // required_if takes a list of matching values: the checkbox arrives as "1"
            // from a form post and as true from an Inertia JSON request.
            'frequency' => 'nullable|required_if:is_recurring,1,true|in:'.$frequencies,
            'next_payment_date' => 'nullable|required_if:is_recurring,1,true|date|after_or_equal:payment_date',
        ];

        $messages = [
            'type.required' => 'Choisissez un type de transaction.',
            'type.in' => 'Type de transaction inconnu.',
            'user_id.exists' => "Cette personne n'existe plus.",
            'category.required_if' => 'Choisissez une catégorie de dépense.',
            'category.in' => 'Catégorie de dépense inconnue.',
            'amount.required' => 'Saisissez un montant.',
            'amount.numeric' => 'Le montant doit être un nombre.',
            'amount.min' => 'Le montant doit être supérieur à 0.',
            'amount.max' => 'Ce montant est trop élevé.',
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'payment_date.required' => 'Choisissez une date de paiement.',
            'payment_date.date' => 'Date de paiement invalide.',
            'frequency.required_if' => 'Choisissez la fréquence de répétition.',
            'frequency.in' => 'Fréquence inconnue.',
            'next_payment_date.required_if' => 'Choisissez la date du prochain paiement.',
            'next_payment_date.after_or_equal' => 'Le prochain paiement ne peut pas précéder celui-ci.',
        ];

        $validated = $request->validate($rules, $messages);

        // NOTE: `rest` is absent from $rules on purpose, so it can never come in from the
        // request. The form computed it in JavaScript and posted it; update() saved that
        // value verbatim, and the form only recalculated it for salaries — so every edited
        // teacher payout recorded the full wallet balance as the remainder instead of what
        // was actually left. It is computed server-side in applyPaymentRules().

        // "Autre" means the admin typed the category, so store what they typed.
        if (($validated['category'] ?? null) === 'other' && $request->filled('custom_category')) {
            $validated['category'] = trim($request->input('custom_category'));
        }

        unset($validated['custom_category']);

        return $validated;
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
                return;
            }

            // withTrashed: reverting/applying a transaction must still resolve
            // an employee whose login was soft-deleted, or the wallet half of
            // the movement silently skips and the ledger drifts.
            $user = User::withTrashed()->find($transaction->user_id);
            if (! $user) {
                return;
            }

            // Wallet movements go through TeacherWalletService — same as the revert half
            // below, which already did. These two branches used `$teacher->wallet += ...;
            // $teacher->save();`, which bypassed the ledger entirely: no ledger row, no
            // row lock, no transaction, no idempotency. Within a single update() call the
            // revert half was ledgered and the apply half was not.
            //
            // ArchitectureTest could not see it either: its regex only matched
            // increment('wallet')/decrement('wallet'), and the `+=` form walked straight
            // past. That regex is now widened to cover this shape too.
            //
            // invoice_id is null here on purpose, which exempts these from the idempotency
            // constraint — a teacher can legitimately be paid out repeatedly.
            $walletService = new \App\Services\TeacherWalletService;

            if ($transaction->type === 'wallet' && $user->role === 'teacher') {
                $teacher = $user->teacher;
                if ($teacher) {
                    $walletService->credit(
                        $teacher,
                        (float) $transaction->amount,
                        \App\Models\TeacherWalletEntry::REASON_ADJUSTMENT,
                        null,
                        null,
                        null,
                        'wallet top-up (transaction #'.$transaction->id.')'
                    );
                } else {
                    Log::error('updateEmployeeBalance: Teacher model not found for user', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                }
            } elseif ($transaction->type === 'payment' && $user->role === 'teacher') {
                $teacher = $user->teacher;
                if (! $teacher) {
                    throw new \Exception("Teacher model not found for user ID: {$user->id}");
                }

                // Checked before the debit because TeacherWalletService::debit() clamps at
                // zero rather than failing — without this an over-payout would silently
                // succeed at a smaller amount than the transaction records.
                if ((float) $teacher->wallet < (float) $transaction->amount) {
                    throw new \Exception("Insufficient funds in teacher wallet. Available: {$teacher->wallet}, Required: {$transaction->amount}");
                }

                $walletService->debit(
                    $teacher,
                    (float) $transaction->amount,
                    \App\Models\TeacherWalletEntry::REASON_PAYOUT,
                    null,
                    null,
                    null,
                    'payout to teacher (transaction #'.$transaction->id.')'
                );
            }

            // Handle other transaction types (salary, etc.) if needed
        } catch (\Exception $e) {
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

        if (! $userId) {
            return;
        }

        // withTrashed: same ledger-drift guard as updateEmployeeBalance —
        // payouts to a since-soft-deleted login's record must still reconcile.
        $user = User::withTrashed()->find($userId);
        if (! $user) {
            return;
        }

        // Wallet movements go through TeacherWalletService so they land in the append-only
        // ledger and the cached balance is updated under a row lock. invoice_id is null
        // here, which deliberately exempts payouts from the idempotency constraint — the
        // same teacher can legitimately be paid out repeatedly.
        $walletService = new \App\Services\TeacherWalletService;

        if ($type === 'wallet' && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if ($teacher) {
                $walletService->debit(
                    $teacher,
                    $amount,
                    \App\Models\TeacherWalletEntry::REASON_PAYOUT,
                    null,
                    null,
                    null,
                    'wallet payout to teacher'
                );
            }
        } elseif ($type === 'payment' && $user->role === 'teacher') {
            // For payment reversals, add back to wallet
            $teacher = $user->teacher;
            if ($teacher) {
                $walletService->credit(
                    $teacher,
                    $amount,
                    \App\Models\TeacherWalletEntry::REASON_ADJUSTMENT,
                    null,
                    null,
                    null,
                    'payout reversed'
                );
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
    public function showRecurringTransactions(Request $request)
    {
        $month = $request->month ?? \Carbon\Carbon::now()->format('Y-m');
        // Get all recurring transactions
        $recurringTransactions = Transaction::where('is_recurring', 1)
            ->with('user', 'recurringPayments')
            ->get()
            ->map(function ($transaction) use ($month) {
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
                    'paid_this_period' => $transaction->recurringPayments->contains(function ($payment) use ($month) {
                        return $payment->period === $month;
                    }),
                ];
            });

        return Inertia::render('Payments/RecurringTransactionsPage', [
            'recurringTransactions' => $recurringTransactions,
            'selectedMonth' => $month,
        ]);
    }

    /**
     * Display the list of recurring transactions with filter
     */
    public function recurringTransactions(Request $request)
    {
        $month = $request->month ?? Carbon::now()->format('Y-m');

        // Parse the month filter
        $startDate = Carbon::parse($month.'-01')->startOfMonth();
        $endDate = Carbon::parse($month.'-01')->endOfMonth();

        // Get all recurring transactions
        $recurringTransactions = Transaction::where('is_recurring', 1)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('next_payment_date', [$startDate, $endDate])
                    ->orWhereNull('next_payment_date');
            })
            ->with('recurringPayments')
            ->get();

        // Set paid_this_period for each transaction based on recurringPayments for the period
        $recurringTransactions = $recurringTransactions->map(function ($transaction) use ($month) {
            $transaction->paid_this_period = $transaction->recurringPayments->contains(function ($payment) use ($month) {
                return $payment->period === $month;
            });

            return $transaction;
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
            $startDate = Carbon::parse($month.'-01')->startOfMonth();
            $endDate = Carbon::parse($month.'-01')->endOfMonth();

            // Get all recurring transactions
            $recurringTransactions = Transaction::where('is_recurring', 1)
                ->where(function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('next_payment_date', [$startDate, $endDate])
                        ->orWhereNull('next_payment_date');
                })
                ->with('recurringPayments')
                ->get();

            if ($recurringTransactions->isEmpty()) {
                return redirect()->back()->with('warning', 'Aucune récurrence prévue pour ce mois.');
            }

            // Skip anyone who already has a payment recorded in this month, whatever its
            // origin. Restricted to the types that pay a person: the old version matched
            // ANY transaction in the window, so a single expense row — which carries the
            // admin's user_id — marked the admin as paid and silently suppressed every
            // recurrence attached to them.
            $paidUserIds = Transaction::whereIn('type', [Transaction::TYPE_SALARY, Transaction::TYPE_PAYMENT])
                ->where('is_recurring', 0)
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->pluck('user_id')
                ->filter()
                ->all();

            $due = $recurringTransactions->reject(
                fn ($t) => $t->user_id && in_array($t->user_id, $paidUserIds, true)
            );

            if ($due->isEmpty()) {
                return redirect()->back()->with('success', 'Toutes les récurrences de ce mois ont déjà été traitées.');
            }

            $outcome = $this->recurringOutcome($this->runRecurring($due));

            return redirect()->back()->with($outcome['key'], $outcome['message']);
        } catch (\Throwable $e) {
            Log::error('Recurring month run failed', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Le traitement des récurrences a échoué : '.$e->getMessage());
        }
    }

    /**
     * Process one recurring transaction now.
     */
    public function processSingleRecurringTransaction($id)
    {
        $transaction = Transaction::findOrFail($id);

        if (! $transaction->is_recurring) {
            return redirect()->back()->with('error', "Cette transaction n'est pas récurrente.");
        }

        $outcome = $this->recurringOutcome($this->runRecurring([$transaction]));

        return redirect()->back()->with($outcome['key'], $outcome['message']);
    }

    /**
     * Process the recurring transactions the admin ticked.
     */
    public function processSelectedRecurringTransactions(Request $request)
    {
        $ids = $request->input('transactions', []);

        if (empty($ids) || ! is_array($ids)) {
            return redirect()->back()->with('error', 'Aucune récurrence sélectionnée.');
        }

        // One query instead of Transaction::find() inside the loop, and is_recurring is
        // filtered here rather than skipped silently in the body — a selected id that is
        // not a recurrence used to vanish from the count with no explanation.
        $templates = Transaction::whereIn('id', $ids)->where('is_recurring', 1)->get();

        if ($templates->isEmpty()) {
            return redirect()->back()->with('error', 'Aucune récurrence valide dans la sélection.');
        }

        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $paidUserIds = Transaction::whereIn('type', [Transaction::TYPE_SALARY, Transaction::TYPE_PAYMENT])
            ->where('is_recurring', 0)
            ->whereBetween('payment_date', [$start, $end])
            ->pluck('user_id')
            ->filter()
            ->all();

        $alreadyPaid = [];
        $due = $templates->reject(function ($t) use ($paidUserIds, &$alreadyPaid) {
            if ($t->user_id && in_array($t->user_id, $paidUserIds, true)) {
                $alreadyPaid[] = ($t->user_name ?: 'Récurrence n°'.$t->id).' : déjà payé ce mois-ci.';

                return true;
            }

            // Guard against the same payee appearing twice in one selection. The old loop
            // appended to $paidUserIds as it went, which this preserves.
            $paidUserIds[] = $t->user_id;

            return false;
        });

        $result = $this->runRecurring($due);
        $result['skipped'] = array_merge($alreadyPaid, $result['skipped']);
        $outcome = $this->recurringOutcome($result);

        return redirect()->back()->with($outcome['key'], $outcome['message']);
    }

    /**
     * Process every recurring transaction that has come due.
     */
    public function processAllRecurringTransactions()
    {
        // The due filter is a WHERE now, not a Carbon comparison inside a foreach over
        // every recurrence in the table. A NULL next_payment_date counts as due: it means
        // the recurrence has never run, and the old code parsed the null into today's date
        // and then compared it to today, so whether it ran at all depended on the time of
        // day the button was pressed.
        $due = Transaction::where('is_recurring', 1)
            ->where(function ($q) {
                $q->whereNull('next_payment_date')
                    ->orWhereDate('next_payment_date', '<=', Carbon::today());
            })
            ->get();

        if ($due->isEmpty()) {
            return redirect()->back()->with('warning', 'Aucune récurrence à traiter aujourd\'hui.');
        }

        $outcome = $this->recurringOutcome($this->runRecurring($due));

        return redirect()->back()->with($outcome['key'], $outcome['message']);
    }

    /**
     * API: Get teacher earnings per month (paid only)
     * Optional filters: teacher_id, month (YYYY-MM)
     * Returns: [{teacherId, teacherName, month, year, totalEarned}]
     */
    public function teacherMonthlyEarningsReport(Request $request)
    {
        $teacherId = $request->input('teacher_id');
        $month = $request->input('month'); // format: YYYY-MM
        $schoolId = $request->input('school_id');
        $classId = $request->input('class_id');

        // Get all memberships with payments (including partial payments) - same approach as TeacherController
        $memberships = \App\Models\Membership::withTrashed()
            ->when($schoolId, function ($q) use ($schoolId) {
                $q->whereHas('student', function ($q2) use ($schoolId) {
                    $q2->where('schoolId', $schoolId);
                });
            })
            ->when($classId, function ($q) use ($classId) {
                $q->whereHas('student', function ($q2) use ($classId) {
                    $q2->where('classId', $classId);
                });
            })
            ->when($teacherId, function ($q) use ($teacherId) {
                $q->whereJsonContains('teachers', [['teacherId' => (string) $teacherId]]);
            })
            ->with(['invoices' => function ($query) {
                // Only include non-deleted invoices (same as TeacherController)
                $query->whereNull('deleted_at');
            }, 'student', 'student.school', 'student.class', 'offer'])
            ->get();

        // Extract invoices from memberships
        $invoices = $memberships->flatMap(function ($membership) {
            return $membership->invoices;
        });

        $earnings = [];
        foreach ($invoices as $invoice) {
            $membership = $invoice->membership;
            if (! $membership || ! is_array($membership->teachers)) {
                continue;
            }

            // Get the selected months for this invoice
            $selectedMonths = $invoice->selected_months ?? [];

            // Ensure selected_months is an array (handle JSON string case)
            if (is_string($selectedMonths)) {
                $selectedMonths = json_decode($selectedMonths, true) ?? [];
            }

            if (empty($selectedMonths)) {
                // Fallback: if no selected_months, use the billDate month; if missing, use created_at month
                if ($invoice->billDate) {
                    $selectedMonths = [$invoice->billDate->format('Y-m')];
                } else {
                    $createdMonth = $invoice->created_at ? $invoice->created_at->format('Y-m') : null;
                    $selectedMonths = [$createdMonth];
                }
            }

            // Determine bill month (format YYYY-MM) for possible partial-month inclusion
            $billMonth = $invoice->billDate ? ($invoice->billDate instanceof \Carbon\Carbon ? $invoice->billDate->format('Y-m') : date('Y-m', strtotime($invoice->billDate))) : null;
            if (! $billMonth) {
                $billMonth = $invoice->created_at ? $invoice->created_at->format('Y-m') : null;
            }

            // If this invoice includes a partial month payment, ensure the bill month is present
            // so the partial-month row can appear when filtering by the bill month (current month).
            if ($invoice->includePartialMonth && $invoice->partialMonthAmount > 0 && $billMonth) {
                if (! in_array($billMonth, $selectedMonths)) {
                    // Add billMonth to selectedMonths so partial-month row appears when filtering by bill month
                    $selectedMonths[] = $billMonth;
                }
            }

            foreach ($membership->teachers as $teacherData) {
                if (! isset($teacherData['teacherId'])) {
                    continue;
                }
                if ($teacherId && (string) $teacherData['teacherId'] !== (string) $teacherId) {
                    continue;
                }

                $teacher = \App\Models\Teacher::find($teacherData['teacherId']);
                if (! $teacher) {
                    continue;
                }

                // Calculate teacher earnings per month based on Offer percentage
                $offer = $invoice->offer;
                $teacherSubject = $teacherData['subject'] ?? ($teacher->subjects->first()->name ?? 'Unknown');

                // Use 0% when offer/subject mapping is missing. Lookup is case-insensitive
                // so this report agrees with what the wallet was actually credited.
                // @see \App\Support\OfferPercentages
                $teacherPercentage = OfferPercentages::forSubject($offer, $teacherSubject) ?? 0;

                // Calculate teacher earnings per month (respect partial-month logic like TeacherController)
                $totalTeacherAmount = $invoice->amountPaid * ($teacherPercentage / 100);
                $monthsCount = count($selectedMonths);

                // Get partial month information
                $includePartialMonth = $invoice->includePartialMonth ?? false;
                $partialMonthAmount = $invoice->partialMonthAmount ?? 0;

                // billMonth already calculated above

                // Calculate per-month amounts taking includePartialMonth into account
                $teacherAmountForPartial = 0;
                $fullMonthsAmount = 0;
                $countFullMonths = 0;

                if ($includePartialMonth && $partialMonthAmount > 0) {
                    $teacherAmountForPartial = $partialMonthAmount * ($teacherPercentage / 100);

                    // Count full months (exclude billMonth if it was inserted for partial)
                    $countFullMonths = count(array_filter($selectedMonths, function ($m) use ($billMonth) {
                        return $m !== $billMonth;
                    }));

                    $remainingTeacherAmount = $totalTeacherAmount - $teacherAmountForPartial;
                    if ($remainingTeacherAmount < 0) {
                        // Safety: if numbers are inconsistent, fallback to equal split across months
                        $remainingTeacherAmount = max(0, $totalTeacherAmount);
                    }

                    if ($countFullMonths > 0) {
                        $fullMonthsAmount = $remainingTeacherAmount / $countFullMonths;
                    } else {
                        $fullMonthsAmount = 0;
                    }
                } else {
                    // No partial month: split total across all selected months
                    $countFullMonths = count($selectedMonths);
                    $fullMonthsAmount = $countFullMonths > 0 ? ($totalTeacherAmount / $countFullMonths) : 0;
                }

                // Distribute earnings across all selected months
                foreach ($selectedMonths as $selectedMonth) {
                    if (empty($selectedMonth)) {
                        continue;
                    }

                    // Filter by month if specified
                    if (! empty($month) && $selectedMonth !== $month) {
                        continue;
                    }

                    $year = substr($selectedMonth, 0, 4);
                    $key = $teacher->id.'-'.$selectedMonth;

                    if (! isset($earnings[$key])) {
                        $earnings[$key] = [
                            'teacherId' => $teacher->id,
                            'teacherName' => $teacher->first_name.' '.$teacher->last_name,
                            'month' => $selectedMonth,
                            'year' => $year,
                            'totalEarned' => 0,
                            'invoiceCount' => 0,
                            'lastPaymentDate' => null,
                        ];
                    }

                    // Calculate the correct amount for this month
                    $amountForThisMonth = ($includePartialMonth && $partialMonthAmount > 0 && $selectedMonth === $billMonth) ? $teacherAmountForPartial : $fullMonthsAmount;
                    $earnings[$key]['totalEarned'] += $amountForThisMonth;
                    $earnings[$key]['invoiceCount'] += 1; // Count each month as separate invoice (same as TeacherController)

                    // Update lastPaymentDate if this invoice is newer
                    $currentDate = $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null;
                    if ($currentDate && ($earnings[$key]['lastPaymentDate'] === null || $currentDate > $earnings[$key]['lastPaymentDate'])) {
                        $earnings[$key]['lastPaymentDate'] = $currentDate;
                    }
                }
            }
        }

        // Clean up temporary tracking arrays
        foreach ($earnings as &$earning) {
            unset($earning['_processed_invoices']);
        }

        // Return as array
        return response()->json(array_values($earnings));
    }

    /**
     * API: Get invoice breakdown for a teacher and month (paid only)
     * Params: teacher_id (required), month (YYYY-MM, required)
     * Returns: [{invoiceId, date, studentName, offerName, amountPaid, teacherShare}]
     */
    public function teacherInvoiceBreakdown(Request $request)
    {
        $teacherId = $request->input('teacher_id');
        $month = $request->input('month'); // format: YYYY-MM
        $schoolId = $request->input('school_id');
        $classId = $request->input('class_id');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);

        if (! $teacherId) {
            return response()->json(['error' => 'teacher_id is required'], 400);
        }

        // Get all memberships with payments (including partial payments) - same approach as TeacherController
        $memberships = \App\Models\Membership::withTrashed()
            ->when($schoolId, function ($q) use ($schoolId) {
                $q->whereHas('student', function ($q2) use ($schoolId) {
                    $q2->where('schoolId', $schoolId);
                });
            })
            ->when($classId, function ($q) use ($classId) {
                $q->whereHas('student', function ($q2) use ($classId) {
                    $q2->where('classId', $classId);
                });
            })
            ->when($teacherId, function ($q) use ($teacherId) {
                $q->whereJsonContains('teachers', [['teacherId' => (string) $teacherId]]);
            })
            ->with(['invoices' => function ($query) {
                // Only include non-deleted invoices (same as TeacherController)
                $query->whereNull('deleted_at');
            }, 'student', 'student.school', 'student.class', 'offer'])
            ->get();

        // Extract invoices from memberships
        $invoices = $memberships->flatMap(function ($membership) {
            return $membership->invoices;
        });

        $result = [];
        foreach ($invoices as $invoice) {
            $membership = $invoice->membership;
            if (! $membership || ! is_array($membership->teachers)) {
                continue;
            }

            // Get the selected months for this invoice
            $selectedMonths = $invoice->selected_months ?? [];

            // Ensure selected_months is an array (handle JSON string case)
            if (is_string($selectedMonths)) {
                $selectedMonths = json_decode($selectedMonths, true) ?? [];
            }

            if (empty($selectedMonths)) {
                // Fallback: if no selected_months, use the billDate month; if missing, fallback to created_at month
                if ($invoice->billDate) {
                    $selectedMonths = [$invoice->billDate->format('Y-m')];
                } else {
                    $createdMonth = $invoice->created_at ? $invoice->created_at->format('Y-m') : null;
                    $selectedMonths = [$createdMonth];
                }
            }

            // Handle partial month invoices - add billMonth to selectedMonths if needed
            $includePartialMonth = $invoice->includePartialMonth ?? false;
            $partialMonthAmount = $invoice->partialMonthAmount ?? 0;
            $billMonth = $invoice->billDate ? ($invoice->billDate instanceof \Carbon\Carbon ? $invoice->billDate->format('Y-m') : date('Y-m', strtotime($invoice->billDate))) : null;
            if (! $billMonth) {
                $billMonth = $invoice->created_at ? $invoice->created_at->format('Y-m') : null;
            }

            // If this invoice includes a partial month payment, ensure the bill month is present
            if ($includePartialMonth && $partialMonthAmount > 0 && $billMonth) {
                if (! in_array($billMonth, $selectedMonths)) {
                    array_unshift($selectedMonths, $billMonth);
                }
            }

            // Create one row per month (same logic as TeacherController)
            foreach ($selectedMonths as $selectedMonth) {
                if (empty($selectedMonth)) {
                    continue;
                }

                // Filter by month if specified (skip if month is "all")
                if ($month && ! empty($month) && $month !== 'all' && $selectedMonth !== $month) {
                    continue;
                }

                $teacherShare = null;
                foreach ($membership->teachers as $teacherData) {
                    if (isset($teacherData['teacherId']) && (string) $teacherData['teacherId'] === (string) $teacherId) {
                        // Calculate teacher share for this specific month based on Offer percentage
                        $offer = $invoice->offer;
                        $teacher = \App\Models\Teacher::find($teacherData['teacherId']);
                        $teacherSubject = $teacherData['subject'] ?? ($teacher ? $teacher->subjects->first()->name : null) ?? 'Unknown';

                        // Use 0% when offer/subject mapping is missing
                        $teacherPercentage = 0;
                        if ($offer && $teacherSubject && is_array($offer->percentage)) {
                            // Case-insensitive, matching the payout path.
                            // @see \App\Support\OfferPercentages
                            $teacherPercentage = OfferPercentages::forSubject($offer, $teacherSubject) ?? 0;

                            // Calculate teacher earnings per month (respect partial-month logic like TeacherController)
                            $totalTeacherAmount = $invoice->amountPaid * ($teacherPercentage / 100);
                            $monthsCount = count($selectedMonths);

                            // Partial month information already calculated above

                            if ($monthsCount > 0) {
                                // Calculate per-month amounts taking includePartialMonth into account
                                $teacherAmountForPartial = 0;
                                $fullMonthsAmount = 0;
                                $countFullMonths = 0;

                                if ($includePartialMonth && $partialMonthAmount > 0) {
                                    $teacherAmountForPartial = $partialMonthAmount * ($teacherPercentage / 100);

                                    // Count full months (exclude billMonth if it was inserted for partial)
                                    $countFullMonths = count(array_filter($selectedMonths, function ($m) use ($billMonth) {
                                        return $m !== $billMonth;
                                    }));

                                    $remainingTeacherAmount = $totalTeacherAmount - $teacherAmountForPartial;
                                    if ($remainingTeacherAmount < 0) {
                                        // Safety: if numbers are inconsistent, fallback to equal split across months
                                        $remainingTeacherAmount = max(0, $totalTeacherAmount);
                                    }

                                    if ($countFullMonths > 0) {
                                        $fullMonthsAmount = $remainingTeacherAmount / $countFullMonths;
                                    } else {
                                        $fullMonthsAmount = 0;
                                    }
                                } else {
                                    // No partial month: split total across all selected months
                                    $countFullMonths = count($selectedMonths);
                                    $fullMonthsAmount = $countFullMonths > 0 ? ($totalTeacherAmount / $countFullMonths) : 0;
                                }

                                // Calculate the correct amount for this specific month
                                $teacherShare = ($includePartialMonth && $partialMonthAmount > 0 && $selectedMonth === $billMonth) ? $teacherAmountForPartial : $fullMonthsAmount;
                            } else {
                                $teacherShare = 0;
                            }
                            break;
                        }
                    }
                }

                // Only create row if teacher share was calculated successfully
                if ($teacherShare !== null) {
                    $student = $invoice->student;
                    $offer = $invoice->offer;
                    $result[] = [
                        'invoiceId' => $invoice->id,
                        'date' => $selectedMonth.'-01', // Use month start date like TeacherController
                        'studentName' => $student ? ($student->firstName.' '.$student->lastName) : '',
                        'offerName' => $offer ? $offer->offer_name : '',
                        'amountPaid' => $invoice->amountPaid,
                        'teacherShare' => $teacherShare,
                        'month' => $selectedMonth, // Add month for reference
                    ];
                }
            }
        }

        // Apply manual pagination to the filtered results
        $total = count($result);
        $lastPage = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedResult = array_slice($result, $offset, $perPage);

        return response()->json([
            'data' => $paginatedResult,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total),
            ],
        ]);
    }

    /**
     * API: Filtered monthly stats for given month/year
     * Expected by route name 'admin.filtered.monthly.stats'.
     * Keep response shape compatible with frontend; return success=false so UI can fallback.
     */
    public function getFilteredMonthlyStats(Request $request)
    {
        try {
            $month = (int) $request->query('month'); // 1-12
            $year = (int) $request->query('year');

            if ($month < 1 || $month > 12 || $year < 2000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid month or year',
                ], 200);
            }

            $targetMonthKey = sprintf('%04d-%02d', $year, $month);

            // Revenue from invoices distributed across selected_months
            $invoices = DB::table('invoices')
                ->select('id', 'billDate', 'amountPaid', 'selected_months', 'includePartialMonth', 'partialMonthAmount')
                ->whereNull('deleted_at')
                ->get();

            $totalRevenue = 0.0;
            $invoiceCountForMonth = 0;

            foreach ($invoices as $invoice) {
                $selectedMonths = json_decode($invoice->selected_months, true);
                if (is_string($selectedMonths)) {
                    $selectedMonths = json_decode($selectedMonths, true);
                }
                if (! is_array($selectedMonths) || empty($selectedMonths)) {
                    // Fallback: use billDate month
                    if (! empty($invoice->billDate)) {
                        $date = Carbon::parse($invoice->billDate);
                        $selectedMonths = [$date->format('Y-m')];
                    } else {
                        $selectedMonths = [];
                    }
                }

                // Handle partial month invoices - add billMonth to selectedMonths if needed
                $includePartialMonth = $invoice->includePartialMonth ?? false;
                $partialMonthAmount = $invoice->partialMonthAmount ?? 0;
                $billMonth = null;

                if (! empty($invoice->billDate)) {
                    $billMonth = Carbon::parse($invoice->billDate)->format('Y-m');
                }

                if ($includePartialMonth && $partialMonthAmount > 0 && $billMonth) {
                    if (! in_array($billMonth, $selectedMonths)) {
                        array_unshift($selectedMonths, $billMonth);
                    }
                }

                if (in_array($targetMonthKey, $selectedMonths, true)) {
                    $monthsCount = max(count($selectedMonths), 1);
                    $amountPerMonth = (float) $invoice->amountPaid / $monthsCount;
                    $totalRevenue += $amountPerMonth;
                    $invoiceCountForMonth += 1;
                }
            }

            // Expenses split into salaries, payments, expenses for the month
            $baseQuery = DB::table('transactions')
                ->whereYear('payment_date', $year)
                ->whereMonth('payment_date', $month);

            $totalSalaries = (float) (clone $baseQuery)->where('type', 'salary')->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
            $totalPayments = (float) (clone $baseQuery)->where('type', 'payment')->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));
            $totalExpenses = (float) (clone $baseQuery)->where('type', 'expense')->sum(DB::raw('CAST(amount AS DECIMAL(10,2))'));

            // Counts
            $salaryCount = (clone $baseQuery)->where('type', 'salary')->count();
            $paymentCount = (clone $baseQuery)->where('type', 'payment')->count();
            $expenseCount = (clone $baseQuery)->where('type', 'expense')->count();

            $totalOutflow = $totalSalaries + $totalPayments + $totalExpenses;
            $profit = $totalRevenue - $totalOutflow;

            return response()->json([
                'success' => true,
                'month' => $month,
                'year' => $year,
                'monthName' => $this->formatMonthInFrench($month),
                'stats' => [
                    'totalRevenue' => round($totalRevenue, 2),
                    'totalSalaries' => round($totalSalaries, 2),
                    'totalPayments' => round($totalPayments, 2),
                    'totalExpenses' => round($totalExpenses, 2),
                    'profit' => round($profit, 2),
                ],
                'details' => [
                    'revenue' => ['invoiceCount' => $invoiceCountForMonth],
                    'salaries' => ['salaryCount' => $salaryCount],
                    'payments' => ['paymentCount' => $paymentCount],
                    'expenses' => ['expenseCount' => $expenseCount],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating filtered monthly stats',
            ], 200);
        }
    }

    /**
     * API: Filtered employee data for given month/year
     * Expected by route name 'admin.filtered.employee.data'.
     * Return success=false to allow frontend to use its fallback filtering.
     */
    public function getFilteredEmployeeData(Request $request)
    {
        try {
            $month = (int) $request->query('month'); // 1-12
            $year = (int) $request->query('year');

            if ($month < 1 || $month > 12 || $year < 2000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid month or year',
                ], 200);
            }

            // Do not send an empty employees array to avoid overriding frontend fallback
            return response()->json([
                'success' => false,
                'message' => 'Filtered employee data not implemented yet',
                'month' => $month,
                'year' => $year,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating filtered employee data',
            ], 200);
        }
    }

    /**
     * DEBUG: Detailed monthly revenue breakdown by invoice for verification
     * Route: GET /debug-monthly-revenue?month=9&year=2025
     */
    public function debugMonthlyRevenue(Request $request)
    {
        $month = (int) $request->query('month'); // 1-12
        $year = (int) $request->query('year');
        if ($month < 1 || $month > 12 || $year < 2000) {
            return response()->json([
                'success' => false,
                'message' => 'Provide valid month (1-12) and year',
            ], 200);
        }

        $targetMonthKey = sprintf('%04d-%02d', $year, $month);

        $invoices = DB::table('invoices')
            ->select('id', 'billDate', 'amountPaid', 'selected_months', 'includePartialMonth', 'partialMonthAmount')
            ->whereNull('deleted_at')
            ->get();

        $items = [];
        $totalRevenue = 0.0;
        foreach ($invoices as $invoice) {
            $selectedMonths = json_decode($invoice->selected_months, true);
            if (is_string($selectedMonths)) {
                $selectedMonths = json_decode($selectedMonths, true);
            }
            if (! is_array($selectedMonths) || empty($selectedMonths)) {
                if (! empty($invoice->billDate)) {
                    $date = Carbon::parse($invoice->billDate);
                    $selectedMonths = [$date->format('Y-m')];
                } else {
                    $selectedMonths = [];
                }
            }

            $monthsCount = max(count($selectedMonths), 1);
            if (in_array($targetMonthKey, $selectedMonths, true)) {
                $amountPerMonth = (float) $invoice->amountPaid / $monthsCount;
                $items[] = [
                    'invoiceId' => $invoice->id,
                    'billDate' => $invoice->billDate,
                    'amountPaid' => (float) $invoice->amountPaid,
                    'monthsCount' => $monthsCount,
                    'selectedMonths' => $selectedMonths,
                    'allocatedToTargetMonth' => round($amountPerMonth, 2),
                ];
                $totalRevenue += $amountPerMonth;
            }
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'year' => $year,
            'monthName' => $this->formatMonthInFrench($month),
            'invoiceCount' => count($items),
            'totalRevenue' => round($totalRevenue, 2),
            'items' => $items,
        ]);
    }
}
