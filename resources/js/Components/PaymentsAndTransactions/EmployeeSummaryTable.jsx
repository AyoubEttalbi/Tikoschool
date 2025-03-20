import React, { useState, useEffect } from 'react';
import { formatDate, getInitials, getRoleBadgeColor, getTransactionTypeColor, getTransactionTypeLabel } from './Utils';
import ActionsMenu from './ActionsMenu';
import { router } from '@inertiajs/react';

// Updated currency formatter to use DH instead of $ with error handling
const formatCurrency = (amount) => {
  if (amount === undefined || amount === null) {
    return '0 DH';
  }
  // Ensure the amount is a number and properly formatted
  const numAmount = Number(amount);
  return isNaN(numAmount) ? '0 DH' : `${numAmount.toLocaleString()} DH`;
};

const EmployeeSummaryTable = ({ employeePayments = [], onEdit, onDelete, onView, onMakePayment, onAddExpense }) => {
  const [viewingEmployeeId, setViewingEmployeeId] = useState(null);
  const [selectedMonth, setSelectedMonth] = useState(new Date().getMonth());
  const [selectedYear, setSelectedYear] = useState(new Date().getFullYear());
  const [filteredData, setFilteredData] = useState(employeePayments);
  
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
  
  // Generate years (last 5 years)
  const currentYear = new Date().getFullYear();
  const years = Array.from({ length: 5 }, (_, i) => currentYear - i);
  
  // Filter data based on selected month and year
  useEffect(() => {
    const filtered = employeePayments.map(employee => {
      // Filter transactions based on selected month and year
      const filteredTransactions = employee.transactions ? employee.transactions.filter(transaction => {
        const transactionDate = new Date(transaction.payment_date);
        return (
          transactionDate.getMonth() === selectedMonth && 
          transactionDate.getFullYear() === selectedYear
        );
      }) : [];
      
      // Calculate monthly totals based on filtered transactions
      // Ensure all values are converted to numbers before summing
      const monthlyOwed = filteredTransactions
        .filter(t => t.type === 'salary')
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0);
      
      const monthlyPaid = filteredTransactions
        .filter(t => t.type === 'payment')
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0);
      
      const monthlyExpenses = filteredTransactions
        .filter(t => t.type === 'expense')
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0);
      
      return {
        ...employee,
        transactions: filteredTransactions,
        monthlyOwed: monthlyOwed || 0,
        monthlyPaid: monthlyPaid || 0,
        monthlyExpenses: monthlyExpenses || 0,
        monthlyBalance: (monthlyOwed || 0) - (monthlyPaid || 0) - (monthlyExpenses || 0)
      };
    });
    
    setFilteredData(filtered);
  }, [selectedMonth, selectedYear, employeePayments]);
  
  const handleViewHistory = (employeeId) => {
    router.get(`/employees/${employeeId}/transactions?month=${selectedMonth + 1}&year=${selectedYear}`);
  };

  // Calculate the total amounts properly (as numbers)
  const totalMonthlySalaries = filteredData.reduce((total, emp) => total + (Number(emp.monthlyOwed) || 0), 0);
  const totalMonthlyPayments = filteredData.reduce((total, emp) => total + (Number(emp.monthlyPaid) || 0), 0);
  const totalMonthlyExpenses = filteredData.reduce((total, emp) => total + (Number(emp.monthlyExpenses) || 0), 0);
  const totalMonthlyBalance = totalMonthlySalaries - totalMonthlyPayments - totalMonthlyExpenses;

  return (
    <div className="space-y-4">
      {/* Calendar Filter Controls */}
      <div className="flex items-center space-x-4 bg-white p-4 rounded-lg shadow">
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
        
        <div className="ml-auto">
          <div className="flex items-center space-x-2">
            <span className="text-sm font-medium text-gray-500">Monthly View</span>
            <div className="px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm font-semibold">
              {months.find(m => m.value === selectedMonth)?.label} {selectedYear}
            </div>
          </div>
        </div>
      </div>
      
      {/* Table */}
      <div className="overflow-x-auto">
        <div className="py-4 align-middle inline-block min-w-full">
          <div className="shadow-lg border border-gray-200 sm:rounded-lg">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-100">
                <tr>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Employee</th>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Salary</span>
                      <span className="text-gray-400 text-xxs normal-case">For {months.find(m => m.value === selectedMonth)?.label}</span>
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Paid</span>
                      <span className="text-gray-400 text-xxs normal-case">For {months.find(m => m.value === selectedMonth)?.label}</span>
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Expenses</span>
                      <span className="text-gray-400 text-xxs normal-case">For {months.find(m => m.value === selectedMonth)?.label}</span>
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span>Monthly Balance</span>
                      <span className="text-gray-400 text-xxs normal-case">For {months.find(m => m.value === selectedMonth)?.label}</span>
                    </div>
                  </th>
                  <th scope="col" className="px-6 py-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Total Balance</th>
                  <th scope="col" className="relative px-6 py-4"><span className="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {filteredData.map((employee) => {
                  const totalBalance = (Number(employee.totalOwed) || 0) - (Number(employee.totalPaid) || 0) - (Number(employee.totalExpenses) || 0);
                  const hasMonthlyTransactions = employee.transactions && employee.transactions.length > 0;
                  const lastTransaction = hasMonthlyTransactions ? 
                    employee.transactions.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0] : null;

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
                          {hasMonthlyTransactions ? formatCurrency(employee.monthlyOwed) : '—'}
                        </div>
                        <div className="text-xs text-gray-500">Salary</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          {hasMonthlyTransactions ? formatCurrency(employee.monthlyPaid) : '—'}
                        </div>
                        <div className="text-xs text-gray-500">Payments</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-orange-600">
                          {hasMonthlyTransactions ? formatCurrency(employee.monthlyExpenses) : '—'}
                        </div>
                        <div className="text-xs text-gray-500">Expenses</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className={`text-sm font-semibold ${
                          employee.monthlyBalance > 0 ? 'text-red-600' : 
                            employee.monthlyBalance < 0 ? 'text-green-600' : 'text-gray-600'
                        }`}>
                          {hasMonthlyTransactions ? formatCurrency(employee.monthlyBalance) : '—'}
                        </div>
                        <div className="text-xs text-gray-500">
                          {hasMonthlyTransactions
                            ? employee.monthlyBalance > 0
                              ? 'Outstanding'
                              : 'Settled'
                            : 'No data'
                          }
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className={`text-sm font-semibold ${totalBalance > 0 ? 'text-red-600' : 'text-green-600'}`}>
                          {formatCurrency(totalBalance)}
                        </div>
                        <div className="text-xs text-gray-500">
                          {totalBalance > 0 ? 'Outstanding' : 'Settled'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <ActionsMenu 
                          onView={() => handleViewHistory(employee.userId)}
                          onMakePayment={() => onMakePayment(employee.userId, totalBalance)}
                          onAddExpense={() => onAddExpense(employee.userId)}
                          onEdit={() => onEdit(employee.userId)}
                          onDelete={() => onDelete(employee.userId)}
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
      
      {/* Monthly Summary Section */}
      <div className="bg-white shadow rounded-lg p-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4">Monthly Summary - {months.find(m => m.value === selectedMonth)?.label} {selectedYear}</h3>
        
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-blue-50 rounded-lg p-4">
            <p className="text-sm text-blue-700">Total Monthly Salaries</p>
            <p className="text-2xl font-bold text-blue-900">
              {formatCurrency(totalMonthlySalaries)}
            </p>
          </div>
          
          <div className="bg-green-50 rounded-lg p-4">
            <p className="text-sm text-green-700">Total Monthly Payments</p>
            <p className="text-2xl font-bold text-green-900">
              {formatCurrency(totalMonthlyPayments)}
            </p>
          </div>
          
          <div className="bg-orange-50 rounded-lg p-4">
            <p className="text-sm text-orange-700">Total Monthly Expenses</p>
            <p className="text-2xl font-bold text-orange-900">
              {formatCurrency(totalMonthlyExpenses)}
            </p>
          </div>
          
          <div className="bg-gray-50 rounded-lg p-4">
            <p className="text-sm text-gray-700">Monthly Balance</p>
            <p className="text-2xl font-bold text-gray-900">
              {formatCurrency(totalMonthlyBalance)}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default EmployeeSummaryTable;