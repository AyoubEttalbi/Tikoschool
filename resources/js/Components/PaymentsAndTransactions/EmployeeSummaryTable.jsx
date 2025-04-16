import React, { useState, useEffect } from 'react';
import { formatDate, getInitials, getRoleBadgeColor, getTransactionTypeColor, getTransactionTypeLabel } from './Utils';
import ActionsMenu from './ActionsMenu';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ChevronUpIcon, ChevronDownIcon, FunnelIcon } from '@heroicons/react/24/outline';

// Updated currency formatter to use DH instead of $ with error handling
const formatCurrency = (amount) => {
  if (amount === undefined || amount === null) {
    return '0 DH';
  }
  // Ensure the amount is a number and properly formatted
  const numAmount = Number(amount);
  return isNaN(numAmount) ? '0 DH' : `${numAmount.toLocaleString()} DH`;
};

// Update the isValidDate helper at the top
const isValidDate = (dateString) => {
  if (!dateString) return false;
  const date = new Date(dateString);
  return date instanceof Date && !isNaN(date.getTime());
};

// Add a new helper to parse ISO dates
const parseISODate = (dateString) => {
  if (!dateString) return null;
  try {
    const date = new Date(dateString);
    return isValidDate(date) ? date : null;
  } catch (e) {
    return null;
  }
};

// Add this function after the parseISODate function
const getLastPaymentDate = (employee) => {
  if (!employee.transactions || employee.transactions.length === 0) return null;
  
  const validTransactions = employee.transactions
    .filter(t => 
      (t.type === 'payment' || t.type === 'salary') && 
      t.updated_at && 
      parseISODate(t.updated_at)
    );

  if (validTransactions.length === 0) return null;

  return validTransactions
    .sort((a, b) => {
      const dateA = parseISODate(a.updated_at);
      const dateB = parseISODate(b.updated_at);
      if (!dateA || !dateB) return 0;
      return dateB.getTime() - dateA.getTime();
    })[0].updated_at;
};

const EmployeeSummaryTable = ({ employeePayments = [], adminEarnings = [], onEdit, onView, onMakePayment }) => {
  const [selectedMonth, setSelectedMonth] = useState(null);
  const [selectedYear, setSelectedYear] = useState(null);
  const [viewingEmployeeId, setViewingEmployeeId] = useState(null);
  const [filteredData, setFilteredData] = useState([]);
  const [earningsData, setEarningsData] = useState(null);

  // New state for filters and sorting
  const [filters, setFilters] = useState({
    role: 'all',
    paymentStatus: 'all',
    paymentPeriod: 'all'
  });
  const [sortConfig, setSortConfig] = useState({
    key: 'lastPayment',
    direction: 'desc'
  });
  const [currentPage, setCurrentPage] = useState(1);
  const [showFilters, setShowFilters] = useState(false);
  const itemsPerPage = 10;

  // Ensure employeePayments is always an array
  const safeEmployeePayments = Array.isArray(employeePayments) ? employeePayments : [];

  console.log('Employee Payments:', safeEmployeePayments);
  console.log('Admin Earnings Data:', adminEarnings);
  console.log('Action handlers:', { onEdit, onView, onMakePayment });

  // More detailed logging for debugging
  useEffect(() => {
    if (earningsData?.earnings) {
      console.log('Detailed Earnings Data:', {
        totalEarnings: earningsData.earnings.length,
        availableYears: earningsData.availableYears,
        currentMonth: selectedMonth + 1,
        currentYear: selectedYear,
        matchingEarnings: earningsData.earnings.filter(e => e.month === selectedMonth + 1 && e.year === parseInt(selectedYear))
      });
    }
  }, [earningsData, selectedMonth, selectedYear]);

  // Fetch earnings data when month/year changes
  useEffect(() => {
    // If adminEarnings is provided and has the data we need, use it
    if (adminEarnings?.earnings && adminEarnings.earnings.length > 0) {
      console.log('Using provided adminEarnings:', adminEarnings);
      setEarningsData(adminEarnings);
    } else {
      // Otherwise fetch the data from the API
      console.log('Fetching earnings data from API...');
      axios.get(route('admin.earnings.dashboard'))
        .then(response => {
          console.log('API Response for earnings data:', response.data);
          setEarningsData(response.data);
        })
        .catch(error => {
          console.error('Error fetching admin earnings:', error);
        });
    }
  }, [adminEarnings, selectedMonth, selectedYear]);

  // Generate months for dropdown
  const months = [
    { value: 0, label: 'January' },
    { value: 1, label: 'February' },
    { value: 2, label: 'March' },
    { value: 3, label: 'April' },
    { value: 4, label: 'May' },
    { value: 5, label: 'June' },
    { value: 6, label: 'July' },
    { value: 7, label: 'August' },
    { value: 8, label: 'September' },
    { value: 9, label: 'October' },
    { value: 10, label: 'November' },
    { value: 11, label: 'December' }
  ];

  // Generate years based on available years from the backend
  const currentYear = new Date().getFullYear();
  const availableYears = earningsData?.availableYears || [];
  const years = availableYears.length > 0 ? availableYears : Array.from({ length: 5 }, (_, i) => currentYear - i);

  // Add payment period options
  const paymentPeriods = [
    { value: 'all', label: 'All Time' },
    { value: 'thisMonth', label: 'This Month' },
    { value: 'lastMonth', label: 'Last Month' },
    { value: 'last3Months', label: 'Last 3 Months' },
    { value: 'last6Months', label: 'Last 6 Months' },
    { value: 'thisYear', label: 'This Year' },
    { value: 'neverPaid', label: 'Never Paid' }
  ];

  // Helper function to check if a date falls within a period
  const isWithinPeriod = (date, period) => {
    if (!date) return false;
    const paymentDate = new Date(date);
    const now = new Date();
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const startOfLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const threeMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 3, 1);
    const sixMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 6, 1);
    const startOfYear = new Date(now.getFullYear(), 0, 1);

    switch (period) {
      case 'thisMonth':
        return paymentDate >= startOfMonth;
      case 'lastMonth':
        return paymentDate >= startOfLastMonth && paymentDate < startOfMonth;
      case 'last3Months':
        return paymentDate >= threeMonthsAgo;
      case 'last6Months':
        return paymentDate >= sixMonthsAgo;
      case 'thisYear':
        return paymentDate >= startOfYear;
      case 'neverPaid':
        return false; // This will be handled separately
      default:
        return true;
    }
  };

  // Add useEffect to set initial month and year based on most recent payment
  useEffect(() => {
    if (!selectedMonth || !selectedYear) {
      // Find the most recent payment date across all employees
      let mostRecentDate = null;

      employeePayments.forEach(employee => {
        if (employee.transactions && employee.transactions.length > 0) {
          const validTransactions = employee.transactions
            .filter(t => (t.type === 'payment' || t.type === 'salary') && t.updated_at)
            .map(t => parseISODate(t.updated_at))
            .filter(date => date !== null);

          if (validTransactions.length > 0) {
            const latestTransaction = new Date(Math.max(...validTransactions));
            if (!mostRecentDate || latestTransaction > mostRecentDate) {
              mostRecentDate = latestTransaction;
            }
          }
        }
      });

      // If we found a recent payment, use its month/year, otherwise use current date
      const dateToUse = mostRecentDate || new Date();
      setSelectedMonth(dateToUse.getMonth());
      setSelectedYear(dateToUse.getFullYear());

      console.log('Setting initial date:', {
        date: dateToUse,
        month: dateToUse.getMonth(),
        year: dateToUse.getFullYear(),
        isFromPayment: Boolean(mostRecentDate)
      });
    }
  }, [employeePayments]);

  // Update the filtering useEffect to only run when we have selected month and year
  useEffect(() => {
    if (selectedMonth === null || selectedYear === null) {
      return;
    }

    // First, sort the employees by their last payment date
    const sortedEmployees = [...safeEmployeePayments].sort((a, b) => {
      const lastPaymentA = getLastPaymentDate(a);
      const lastPaymentB = getLastPaymentDate(b);

      // Put employees with no payments at the end
      if (!lastPaymentA && !lastPaymentB) return 0;
      if (!lastPaymentA) return 1;
      if (!lastPaymentB) return -1;

      // Sort by last payment date (most recent first)
      const dateA = parseISODate(lastPaymentA);
      const dateB = parseISODate(lastPaymentB);
      return dateB.getTime() - dateA.getTime();
    });

    const filtered = sortedEmployees.map(employee => {
      // Rest of the processing remains the same
      const filteredTransactions = employee.transactions ? employee.transactions.filter(transaction => {
        if (!transaction.updated_at) return false;
        const transactionDate = parseISODate(transaction.updated_at);
        return transactionDate && 
          transactionDate.getMonth() === selectedMonth && 
          transactionDate.getFullYear() === selectedYear;
      }) : [];

      const lastPaymentDate = getLastPaymentDate(employee);
      
      const monthlySalary = Number(employee.baseSalary) || 0;
      
      const monthlyPaid = filteredTransactions
        .filter(t => t.type === 'payment' || t.type === 'salary')
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0);
      
      const monthlyExpenses = filteredTransactions
        .filter(t => t.type === 'expense')
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0);
      
      const monthlyBalance = monthlySalary - monthlyPaid - monthlyExpenses;
      
      return {
        ...employee,
        transactions: employee.transactions,
        filteredTransactions,
        monthlyOwed: employee.role === 'teacher' ? employee.wallet : employee.baseSalary,
        monthlyPaid: monthlyPaid || 0,
        monthlyExpenses: monthlyExpenses || 0,
        monthlyBalance: monthlyBalance,
        lastPayment: lastPaymentDate,
        hasValidTransactions: Boolean(lastPaymentDate)
      };
    });
    
    setFilteredData(filtered);
  }, [selectedMonth, selectedYear, safeEmployeePayments]);

  const handleViewHistory = (employeeId) => {
    console.log('Viewing history for employee:', employeeId);
    const employee = safeEmployeePayments.find(emp => emp.userId === employeeId);
    if (employee) {
      console.log('Found employee:', employee);
      // Pass the entire employee object with transactions
      onView({
        userId: employee.userId,
        transactions: employee.transactions
      });
    } else {
      console.error('Employee not found:', employeeId);
      console.log('Available employees:', safeEmployeePayments);
    }
  };

  // Calculate the total amounts properly (as numbers)
  const totalMonthlySalaries = filteredData.reduce((total, emp) => total + (Number(emp.monthlyOwed) || 0), 0);
  const totalMonthlyPayments = filteredData.reduce((total, emp) => total + (Number(emp.monthlyPaid) || 0), 0);
  const totalMonthlyExpenses = filteredData.reduce((total, emp) => total + (Number(emp.monthlyExpenses) || 0), 0);

  // Find the matching earnings entry for the selected month and year from the new API structure
  // Since backend API returns month as 1-12 but our dropdown is 0-11, we add 1 to selectedMonth
  const currentMonthEarnings = earningsData?.earnings?.find(
    earnings => earnings.month === selectedMonth + 1 && earnings.year === parseInt(selectedYear)
  );

  console.log('Current Month Earnings:', currentMonthEarnings);
  console.log('Selected Month/Year:', selectedMonth + 1, selectedYear);
  console.log('All Available Earnings:', earningsData?.earnings);

  // Debug: Check if any earnings match the current month/year
  if (earningsData?.earnings) {
    const matchingEntries = earningsData.earnings.filter(
      e => e.month === selectedMonth + 1 && e.year === parseInt(selectedYear)
    );
    console.log(`Found ${matchingEntries.length} matching entries for ${selectedMonth + 1}/${selectedYear}:`, matchingEntries);
  }

  // Get the total revenue for the selected month from the new API structure
  const totalMonthlyRevenue = currentMonthEarnings ? Number(currentMonthEarnings.totalRevenue) : 0;
  const apiTotalMonthlyExpenses = currentMonthEarnings ? Number(currentMonthEarnings.totalExpenses) : 0;

  // Calculate totalMonthlyBalance based on the new API structure
  // If we have matching data, use the profit from the API, otherwise calculate from filtered data
  const totalMonthlyBalance = currentMonthEarnings
    ? Number(currentMonthEarnings.profit)
    : Number(totalMonthlyRevenue) - totalMonthlyPayments - apiTotalMonthlyExpenses;

  // Get unique schools from employee data
  const schools = [...new Set(employeePayments.map(emp => emp.school).filter(Boolean))];

  // Sort function
  const sortData = (data, key, direction) => {
    return [...data].sort((a, b) => {
      if (key === 'lastPayment') {
        const dateA = a.lastPayment ? new Date(a.lastPayment) : new Date(0);
        const dateB = b.lastPayment ? new Date(b.lastPayment) : new Date(0);
        return direction === 'asc' ? dateA - dateB : dateB - dateA;
      }
      if (key === 'userName') {
        return direction === 'asc'
          ? a.userName.localeCompare(b.userName)
          : b.userName.localeCompare(a.userName);
      }
      if (key === 'totalBalance') {
        const balanceA = (Number(a.totalOwed) || 0) - (Number(a.totalPaid) || 0);
        const balanceB = (Number(b.totalOwed) || 0) - (Number(b.totalPaid) || 0);
        return direction === 'asc' ? balanceA - balanceB : balanceB - balanceA;
      }
      return 0;
    });
  };

  // Handle sort click
  const handleSort = (key) => {
    setSortConfig(prevConfig => ({
      key,
      direction: prevConfig.key === key && prevConfig.direction === 'asc' ? 'desc' : 'asc'
    }));
  };

  // Filter and sort data
  useEffect(() => {
    let result = [...employeePayments];

    // Apply filters
    if (filters.role !== 'all') {
      result = result.filter(emp => emp.role === filters.role);
    }
    if (filters.paymentStatus !== 'all') {
      result = result.filter(emp => {
        const balance = (Number(emp.totalOwed) || 0) - (Number(emp.totalPaid) || 0);
        return filters.paymentStatus === 'paid' ? balance <= 0 : balance > 0;
      });
    }
    if (filters.paymentPeriod !== 'all') {
      result = result.filter(emp => {
        if (filters.paymentPeriod === 'neverPaid') {
          return !emp.lastPayment;
        }
        return isWithinPeriod(emp.lastPayment, filters.paymentPeriod);
      });
    }

    // Apply sorting
    result = sortData(result, sortConfig.key, sortConfig.direction);

    setFilteredData(result);
  }, [employeePayments, filters, sortConfig]);

  // Get current page items
  const indexOfLastItem = currentPage * itemsPerPage;
  const indexOfFirstItem = indexOfLastItem - itemsPerPage;
  const currentItems = filteredData.slice(indexOfFirstItem, indexOfLastItem);
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);

  const SortIcon = ({ column }) => {
    if (sortConfig.key !== column) return null;
    return sortConfig.direction === 'asc' ?
      <ChevronUpIcon className="w-4 h-4 inline-block ml-1" /> :
      <ChevronDownIcon className="w-4 h-4 inline-block ml-1" />;
  };

  // Update the formatDate function to handle ISO dates better
  const formatDate = (dateString) => {
    if (!dateString) return 'Never paid';
    const date = parseISODate(dateString);
    if (!date) return 'Never paid';

    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    if (days < 30) return `${Math.floor(days / 7)} weeks ago`;
    if (days < 365) return `${Math.floor(days / 30)} months ago`;
    return `${Math.floor(days / 365)} years ago`;
  };

  // Add loading state for initial render
  if (selectedMonth === null || selectedYear === null) {
    return (
      <div className="flex items-center justify-center p-4">
        <div className="animate-pulse text-gray-600">Loading...</div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Filters Section */}


      {/* Calendar Filter Controls */}
      <div className="bg-white p-4 rounded-lg shadow">
      
        {/* Month and Year Filter Section */}
        <div className="flex items-center flex-wrap space-x-4 mb-4">
          <div className="font-medium text-gray-700">Filter by Month:</div>
          <div className="flex space-x-2">
            <select
              className="bg-white border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(parseInt(e.target.value))}
            >
              {months.map((month) => (
                <option key={month.value} value={month.value}>
                  {month.label}
                </option>
              ))}
            </select>

            <select
              className="bg-white border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              value={selectedYear}
              onChange={(e) => setSelectedYear(parseInt(e.target.value))}
            >
              {years.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-2 -mb-4">
            <button
              onClick={() => setShowFilters(!showFilters)}
              className="flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              <FunnelIcon className="w-4 h-4 mr-2" />
              Filters
            </button>
            {Object.values(filters).some(v => v !== 'all') && (
              <button
                onClick={() => setFilters({ role: 'all', paymentStatus: 'all', paymentPeriod: 'all' })}
                className="text-sm text-red-600 hover:text-red-800"
              >
                Clear Filters
              </button>
            )}
          </div>
          
        </div>
          <div className="ml-auto">
            <div className="flex items-center space-x-2">
              <span className="text-sm font-medium text-gray-500">Monthly View</span>
              <div className="px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm font-semibold">
                {months.find(m => m.value === selectedMonth)?.label} {selectedYear}
              </div>
            </div>
          </div>
          
        </div>

        {/* Filter Controls Section */}
        

        {/* Expandable Filters Panel */}
        {showFilters && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-gray-50 rounded-md">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
              <select
                value={filters.role}
                onChange={(e) => setFilters(prev => ({ ...prev, role: e.target.value }))}
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              >
                <option value="all">All Roles</option>
                <option value="teacher">Teachers</option>
                <option value="assistant">Assistants</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
              <select
                value={filters.paymentStatus}
                onChange={(e) => setFilters(prev => ({ ...prev, paymentStatus: e.target.value }))}
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              >
                <option value="all">All Status</option>
                <option value="paid">Paid</option>
                <option value="unpaid">Unpaid</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Payment Period</label>
              <select
                value={filters.paymentPeriod}
                onChange={(e) => setFilters(prev => ({ ...prev, paymentPeriod: e.target.value }))}
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              >
                {paymentPeriods.map(period => (
                  <option key={period.value} value={period.value}>{period.label}</option>
                ))}
              </select>
            </div>
          </div>
        )}
      </div>

      {/* Table */}
      <div className="overflow-x-auto">
        <div className="py-2 align-middle inline-block min-w-full">
          <div className="shadow overflow-hidden border-b border-gray-200 sm:rounded-lg">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th
                    scope="col"
                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer"
                    onClick={() => handleSort('userName')}
                  >
                    <div className="flex items-center">
                      Employee
                      <SortIcon column="userName" />
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Salary</span>
                      <span className="text-gray-400 text-xxs normal-case">Fixed Amount</span>
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Paid</span>
                      <span className="text-gray-400 text-xxs normal-case">For {months.find(m => m.value === selectedMonth)?.label}</span>
                    </div>
                  </th>
                  <th
                    scope="col"
                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer"
                    onClick={() => handleSort('totalBalance')}
                  >
                    <div className="flex items-center">
                      Balance
                      <SortIcon column="totalBalance" />
                    </div>
                  </th>
                  <th
                    scope="col"
                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                  >
                    <div className="flex items-center">
                      Last Payment
                    </div>
                  </th>
                  <th scope="col" className="relative px-6 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {currentItems.map((employee) => {
                  const totalBalance = (Number(employee.totalOwed) || 0) - (Number(employee.totalPaid) || 0);
                  const hasMonthlyTransactions = employee.transactions && employee.transactions.length > 0;

                  return (
                    <tr key={employee.userId} className="hover:bg-gray-50 transition-colors duration-200">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center">
                          <div className="flex-shrink-0 h-10 w-10">
                            <span className="inline-flex items-center justify-center h-10 w-10 rounded-full bg-blue-100">
                              <span className="text-sm font-medium leading-none text-blue-800">{getInitials(employee.userName || '')}</span>
                            </span>
                          </div>
                          <div className="ml-4">
                            <div className="text-sm font-medium text-gray-900">{employee.userName || 'Unknown'}</div>
                            <div className="text-sm text-gray-500">{employee.email || 'No email'}</div>
                            {employee.role && (
                              <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getRoleBadgeColor(employee.role)}`}>
                                {employee.role}
                              </span>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          {employee.role === 'teacher' ? formatCurrency(employee.wallet) : formatCurrency(employee.baseSalary)}
                        </div>
                        <div className="text-xs text-gray-500">Fixed Amount</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          {hasMonthlyTransactions ? formatCurrency(employee.monthlyPaid) : '—'}
                        </div>
                        <div className="text-xs text-gray-500">Monthly Payment</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className={`text-sm font-semibold ${totalBalance > 0 ? 'text-red-600' : 'text-green-600'}`}>
                          {formatCurrency(totalBalance)}
                        </div>
                        <div className="text-xs text-gray-500">
                          {totalBalance > 0 ? 'Outstanding' : 'Settled'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          {employee.hasValidTransactions ? (
                            <>
                              <div>{formatDate(employee.lastPayment)}</div>
                              <div className="text-xs text-gray-500">
                                {parseISODate(employee.lastPayment)?.toLocaleDateString(undefined, {
                                  year: 'numeric',
                                  month: 'short',
                                  day: 'numeric'
                                })}
                              </div>
                            </>
                          ) : (
                            <span className="text-gray-500">Never paid</span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <ActionsMenu
                          onView={() => handleViewHistory(employee.userId)}
                          onEdit={() => onEdit(employee.transactions[0]?.id)}
                          onMakePayment={() => onMakePayment(employee.userId, totalBalance, employee)}
                          showMakePayment={totalBalance > 0}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="flex items-center justify-between px-4 py-3 bg-white border-t border-gray-200 sm:px-6 rounded-lg shadow">
          <div className="flex justify-between sm:hidden">
            <button
              onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
              disabled={currentPage === 1}
              className="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Previous
            </button>
            <button
              onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
              disabled={currentPage === totalPages}
              className="relative ml-3 inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Next
            </button>
          </div>
          <div className="hidden sm:flex sm:items-center sm:justify-between">
            <div>
              <p className="text-sm text-gray-700">
                Showing <span className="font-medium">{indexOfFirstItem + 1}</span> to{' '}
                <span className="font-medium">{Math.min(indexOfLastItem, filteredData.length)}</span> of{' '}
                <span className="font-medium">{filteredData.length}</span> results
              </p>
            </div>
            <div>
              <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <button
                  onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                  disabled={currentPage === 1}
                  className="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Previous
                </button>
                {[...Array(totalPages)].map((_, i) => (
                  <button
                    key={i + 1}
                    onClick={() => setCurrentPage(i + 1)}
                    className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${currentPage === i + 1
                        ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600'
                        : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                      }`}
                  >
                    {i + 1}
                  </button>
                ))}
                <button
                  onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
                  disabled={currentPage === totalPages}
                  className="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Next
                </button>
              </nav>
            </div>
          </div>
        </div>
      )}

      {/* Monthly Summary Section */}
      <div className="bg-white shadow rounded-lg p-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4">Monthly Summary - {months.find(m => m.value === selectedMonth)?.label} {selectedYear}</h3>

        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div className="bg-purple-50 rounded-lg p-4">
            <p className="text-sm text-purple-700">Total Monthly Revenue</p>
            <p className="text-2xl font-bold text-purple-900">
              {formatCurrency(totalMonthlyRevenue)}
            </p>
            <p className="text-xs text-purple-500 mt-1">From invoices and enrollments</p>
          </div>

          <div className="bg-blue-50 rounded-lg p-4">
            <p className="text-sm text-blue-700">Total Monthly Salaries</p>
            <p className="text-2xl font-bold text-blue-900">
              {formatCurrency(totalMonthlySalaries)}
            </p>
            <p className="text-xs text-blue-500 mt-1">Fixed monthly amounts</p>
          </div>

          <div className="bg-green-50 rounded-lg p-4">
            <p className="text-sm text-green-700">Total Monthly Payments</p>
            <p className="text-2xl font-bold text-green-900">
              {formatCurrency(totalMonthlyPayments)}
            </p>
            <p className="text-xs text-green-500 mt-1">Actual payments made</p>
          </div>

          <div className="bg-orange-50 rounded-lg p-4">
            <p className="text-sm text-orange-700">Total Monthly Expenses</p>
            <p className="text-2xl font-bold text-orange-900">
              {formatCurrency(totalMonthlyExpenses)}
            </p>
            <p className="text-xs text-orange-500 mt-1">All expenses recorded</p>
          </div>

          <div className={`${totalMonthlyBalance >= 0 ? 'bg-green-50' : 'bg-red-50'} rounded-lg p-4`}>
            <p className={`text-sm ${totalMonthlyBalance >= 0 ? 'text-green-700' : 'text-red-700'}`}>Monthly Profit</p>
            <p className={`text-2xl font-bold ${totalMonthlyBalance >= 0 ? 'text-green-900' : 'text-red-900'}`}>
              {formatCurrency(totalMonthlyBalance)}
            </p>
            <p className={`text-xs ${totalMonthlyBalance >= 0 ? 'text-green-500' : 'text-red-500'} mt-1`}>
              {totalMonthlyBalance >= 0 ? 'Net profit' : 'Net loss'}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default EmployeeSummaryTable;