import React, { useState, useMemo, useEffect } from 'react';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

/**
 * Format currency values with Moroccan Dirham (DH) symbol
 */
const formatCurrency = (amount) => {
  if (amount === undefined || amount === null) {
    return '0 DH';
  }
  const numAmount = Number(amount);
  return isNaN(numAmount) ? '0 DH' : `${numAmount.toLocaleString()} DH`;
};

/**
 * Calculate percentage change between two values
 */
const calculateChange = (current, previous) => {
  if (!previous) return { percentage: 0, direction: 'neutral' };
  
  const change = ((current - previous) / previous) * 100;
  return {
    percentage: Math.abs(change).toFixed(1),
    direction: change > 0 ? 'up' : change < 0 ? 'down' : 'neutral'
  };
};

/**
 * Enhanced Admin Earnings Dashboard component
 */
const AdminEarningsSection = ({ adminEarnings }) => {
  const [viewMode, setViewMode] = useState('monthly');
  const [selectedYear, setSelectedYear] = useState(new Date().getFullYear());
  const [visualizationType, setVisualizationType] = useState('bar');
  const [page, setPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(6);
  const [showTrend, setShowTrend] = useState(false);
  
  // Set initial year to most recent year in data
  useEffect(() => {
    if (adminEarnings.length > 0) {
      // Extract years and convert to numbers
      const years = adminEarnings.map(item => Number(item.year));
      // Find the maximum year even if data is not sorted
      const maxYear = Math.max(...years);
      // Ensure we have a valid year before updating state
      if (!isNaN(maxYear) && maxYear > 0) {
        setSelectedYear(maxYear);
      }
    }
  }, [adminEarnings]);
  
  // Get available years from data for year filter
  const availableYears = useMemo(() => {
    const years = [...new Set(adminEarnings.map(item => Number(item.year)))];
    return years.sort((a, b) => b - a);
  }, [adminEarnings]);
  
  const monthOrder = ["January", "February", "March", "April", "May", "June", 
                      "July", "August", "September", "October", "November", "December"];
  
  // Sort function for months
  const sortByMonth = (a, b) => {
    const monthOrderA = monthOrder.indexOf(a.monthName);
    const monthOrderB = monthOrder.indexOf(b.monthName);
    return monthOrderA - monthOrderB;
  };
  
  // Filter data by selected year
  const filteredEarnings = useMemo(() => {
    return adminEarnings
      .filter(item => Number(item.year) === selectedYear)
      .sort(sortByMonth);
  }, [adminEarnings, selectedYear]);
  
  // Get previous year's data for comparison
  const previousYearEarnings = useMemo(() => {
    return adminEarnings
      .filter(item => Number(item.year) === selectedYear - 1)
      .sort(sortByMonth);
  }, [adminEarnings, selectedYear]);
  
  // Pagination for monthly data
  const paginatedEarnings = useMemo(() => {
    const startIndex = (page - 1) * itemsPerPage;
    return filteredEarnings.slice(startIndex, startIndex + itemsPerPage);
  }, [filteredEarnings, page, itemsPerPage]);
  
  // Derived state calculations
  const { 
    chartData, 
    yearlyData, 
    totalRevenue, 
    totalExpenses, 
    totalProfit,
    revenueChange,
    expensesChange,
    profitChange,
    expenseBreakdown
  } = useMemo(() => {
    // For monthly view - all months
    const chartData = filteredEarnings
      .map(item => ({
        name: item.monthName,
        revenue: Number(item.totalRevenue || 0),
        expenses: Number(item.totalExpenses || 0),
        profit: Number(item.profit || 0),
        prevYearRevenue: previousYearEarnings.find(prev => prev.monthName === item.monthName)?.totalRevenue || 0,
        prevYearProfit: previousYearEarnings.find(prev => prev.monthName === item.monthName)?.profit || 0
      }));
    
    // For yearly view - aggregate by quarters
    const quarterData = {};
    filteredEarnings.forEach(item => {
      const monthIndex = monthOrder.indexOf(item.monthName);
      const quarter = Math.floor(monthIndex / 3) + 1;
      const quarterKey = `Q${quarter}`;
      
      if (!quarterData[quarterKey]) {
        quarterData[quarterKey] = { 
          name: quarterKey, 
          revenue: 0, 
          expenses: 0, 
          profit: 0 
        };
      }
      
      quarterData[quarterKey].revenue += Number(item.totalRevenue || 0);
      quarterData[quarterKey].expenses += Number(item.totalExpenses || 0);
      quarterData[quarterKey].profit += Number(item.profit || 0);
    });
    
    const yearlyData = Object.values(quarterData);
    
    // Calculate totals
    const totalRevenue = filteredEarnings.reduce((sum, item) => sum + Number(item.totalRevenue || 0), 0);
    const totalExpenses = filteredEarnings.reduce((sum, item) => sum + Number(item.totalExpenses || 0), 0);
    const totalProfit = filteredEarnings.reduce((sum, item) => sum + Number(item.profit || 0), 0);
    
    // Calculate previous year totals for comparison
    const prevYearRevenue = previousYearEarnings.reduce((sum, item) => sum + Number(item.totalRevenue || 0), 0);
    const prevYearExpenses = previousYearEarnings.reduce((sum, item) => sum + Number(item.totalExpenses || 0), 0);
    const prevYearProfit = previousYearEarnings.reduce((sum, item) => sum + Number(item.profit || 0), 0);
    
    // Calculate year-over-year changes
    const revenueChange = calculateChange(totalRevenue, prevYearRevenue);
    const expensesChange = calculateChange(totalExpenses, prevYearExpenses);
    const profitChange = calculateChange(totalProfit, prevYearProfit);
    
    // Create expense breakdown for the pie chart
    const expenseBreakdown = [
      { name: 'Salaries', value: totalExpenses * 0.45 },
      { name: 'Instructor Payments', value: totalExpenses * 0.25 },
      { name: 'Operations', value: totalExpenses * 0.15 },
      { name: 'Marketing', value: totalExpenses * 0.10 },
      { name: 'Miscellaneous', value: totalExpenses * 0.05 }
    ];
    
    return { 
      chartData, 
      yearlyData, 
      totalRevenue, 
      totalExpenses, 
      totalProfit,
      revenueChange,
      expensesChange,
      profitChange,
      expenseBreakdown
    };
  }, [filteredEarnings, previousYearEarnings]);
  
  // Calculate total pages for pagination
  const totalPages = Math.ceil(filteredEarnings.length / itemsPerPage);
  
  // Custom tooltip for the chart
  const CustomTooltip = ({ active, payload, label }) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white p-4 shadow-lg rounded-md border border-gray-200">
          <p className="font-medium text-gray-900">{label}</p>
          {payload.map((entry, index) => (
            <p key={index} style={{ color: entry.color }} className="text-sm">
              {entry.name}: {formatCurrency(entry.value)}
            </p>
          ))}
        </div>
      );
    }
    return null;
  };
  
  // Year-over-year comparison tooltip
  const ComparisonTooltip = ({ active, payload, label }) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white p-4 shadow-lg rounded-md border border-gray-200">
          <p className="font-medium text-gray-900">{label}</p>
          {payload.map((entry, index) => (
            <p key={index} style={{ color: entry.color }} className="text-sm">
              {entry.name === 'revenue' ? 'Current Revenue: ' : 'Current Profit: '}
              {formatCurrency(entry.value)}
            </p>
          ))}
          {payload.map((entry, index) => {
            if (entry.name === 'revenue' || entry.name === 'profit') {
              const prevValue = entry.name === 'revenue' ? 
                payload.find(p => p.name === 'prevYearRevenue')?.value : 
                payload.find(p => p.name === 'prevYearProfit')?.value;
              
              if (prevValue) {
                const change = calculateChange(entry.value, prevValue);
                return (
                  <p key={`prev-${index}`} className="text-xs mt-1" style={{ color: entry.color }}>
                    {entry.name === 'revenue' ? 'Previous Year: ' : 'Previous Year: '}
                    {formatCurrency(prevValue)}
                    <span className={`ml-2 ${
                      change.direction === 'up' ? 'text-green-600' : 
                      change.direction === 'down' ? 'text-red-600' : 'text-gray-600'
                    }`}>
                      {change.direction === 'up' ? '↑' : change.direction === 'down' ? '↓' : ''}
                      {change.percentage}%
                    </span>
                  </p>
                );
              }
            }
            return null;
          })}
        </div>
      );
    }
    return null;
  };

  return (
    <div className="bg-white shadow-md rounded-lg p-6 mt-6">
      <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
        <h2 className="text-xl font-semibold text-gray-800">Financial Performance Dashboard</h2>
        <div className="flex flex-wrap gap-3">
          {/* Year filter dropdown */}
          <div className="relative">
            <select
              value={selectedYear}
              onChange={(e) => setSelectedYear(Number(e.target.value))}
              className="appearance-none bg-white border border-gray-300 rounded-lg py-2 pl-4 pr-10 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
              {availableYears.map(year => (
                <option key={year} value={year}>{year}</option>
              ))}
            </select>
            <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
              <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd"></path>
              </svg>
            </div>
          </div>
          
          {/* View mode toggle */}
          <div className="inline-flex p-1 bg-gray-100 rounded-lg">
            <button 
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors duration-150 ${
                viewMode === 'monthly' 
                  ? 'bg-white text-indigo-700 shadow-sm' 
                  : 'text-gray-600 hover:bg-gray-50'
              }`}
              onClick={() => setViewMode('monthly')}
            >
              Monthly View
            </button>
            <button 
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors duration-150 ${
                viewMode === 'yearly' 
                  ? 'bg-white text-indigo-700 shadow-sm' 
                  : 'text-gray-600 hover:bg-gray-50'
              }`}
              onClick={() => setViewMode('yearly')}
            >
              Annual Summary
            </button>
          </div>
          
          {/* Visualization type selector */}
          <div className="inline-flex p-1 bg-gray-100 rounded-lg">
            <button 
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors duration-150 ${
                visualizationType === 'bar' 
                  ? 'bg-white text-indigo-700 shadow-sm' 
                  : 'text-gray-600 hover:bg-gray-50'
              }`}
              onClick={() => setVisualizationType('bar')}
            >
              Bar Chart
            </button>
            <button 
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors duration-150 ${
                visualizationType === 'line' 
                  ? 'bg-white text-indigo-700 shadow-sm' 
                  : 'text-gray-600 hover:bg-gray-50'
              }`}
              onClick={() => setVisualizationType('line')}
            >
              Line Chart
            </button>
          </div>
          
          {/* Year-over-year comparison toggle */}
          <div className="ml-0 lg:ml-2">
            <button
              onClick={() => setShowTrend(!showTrend)}
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors duration-150 border ${
                showTrend
                  ? 'bg-indigo-50 text-indigo-700 border-indigo-200'
                  : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
              }`}
            >
              {showTrend ? 'Hide YoY Comparison' : 'Show YoY Comparison'}
            </button>
          </div>
        </div>
      </div>
      
      {/* Summary cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div className="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-5 shadow-sm border border-blue-100">
          <div className="flex items-center mb-2">
            <div className="rounded-full bg-blue-100 p-2 mr-3">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <p className="text-sm font-medium text-blue-800">Total Revenue</p>
          </div>
          <div className="flex items-baseline">
            <p className="text-2xl font-bold text-blue-900 mb-1">
              {formatCurrency(totalRevenue)}
            </p>
            {revenueChange.percentage > 0 && (
              <span className={`ml-2 text-xs font-medium ${
                revenueChange.direction === 'up' ? 'text-green-600' : 
                revenueChange.direction === 'down' ? 'text-red-600' : 'text-gray-600'
              }`}>
                {revenueChange.direction === 'up' ? '↑' : revenueChange.direction === 'down' ? '↓' : ''}
                {revenueChange.percentage}%
              </span>
            )}
          </div>
          <p className="text-xs text-blue-700">
            From student payments & course enrollments
          </p>
        </div>
        
        <div className="bg-gradient-to-br from-red-50 to-orange-50 rounded-xl p-5 shadow-sm border border-red-100">
          <div className="flex items-center mb-2">
            <div className="rounded-full bg-red-100 p-2 mr-3">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
            <p className="text-sm font-medium text-red-800">Total Expenses</p>
          </div>
          <div className="flex items-baseline">
            <p className="text-2xl font-bold text-red-900 mb-1">
              {formatCurrency(totalExpenses)}
            </p>
            {expensesChange.percentage > 0 && (
              <span className={`ml-2 text-xs font-medium ${
                expensesChange.direction === 'up' ? 'text-red-600' : 
                expensesChange.direction === 'down' ? 'text-green-600' : 'text-gray-600'
              }`}>
                {expensesChange.direction === 'up' ? '↑' : expensesChange.direction === 'down' ? '↓' : ''}
                {expensesChange.percentage}%
              </span>
            )}
          </div>
          <p className="text-xs text-red-700">
            Salaries, operations & instructor payments
          </p>
        </div>
        
        <div className="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-5 shadow-sm border border-green-100">
          <div className="flex items-center mb-2">
            <div className="rounded-full bg-green-100 p-2 mr-3">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <p className="text-sm font-medium text-green-800">Net Profit</p>
          </div>
          <div className="flex items-baseline">
            <p className="text-2xl font-bold text-green-900 mb-1">
              {formatCurrency(totalProfit)}
            </p>
            {profitChange.percentage > 0 && (
              <span className={`ml-2 text-xs font-medium ${
                profitChange.direction === 'up' ? 'text-green-600' : 
                profitChange.direction === 'down' ? 'text-red-600' : 'text-gray-600'
              }`}>
                {profitChange.direction === 'up' ? '↑' : profitChange.direction === 'down' ? '↓' : ''}
                {profitChange.percentage}%
              </span>
            )}
          </div>
          <p className="text-xs text-green-700">
            Net earnings after all expenses
          </p>
        </div>
      </div>
      
      {/* Revenue chart */}
      <div className="bg-gray-50 rounded-xl p-5 mb-8 border border-gray-100">
        <h3 className="text-lg font-medium text-gray-800 mb-4">
          {viewMode === 'monthly' 
            ? `Monthly Performance Trend (${selectedYear})` 
            : `Quarterly Performance Trend (${selectedYear})`}
        </h3>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            {visualizationType === 'bar' ? (
              <BarChart 
                data={viewMode === 'monthly' ? chartData : yearlyData} 
                margin={{ top: 10, right: 30, left: 0, bottom: 10 }}
                barGap={8}
                barSize={24}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                <XAxis 
                  dataKey="name" 
                  axisLine={false} 
                  tickLine={false} 
                  tick={{ fill: '#6b7280', fontSize: 12 }}
                />
                <YAxis 
                  axisLine={false} 
                  tickLine={false} 
                  tick={{ fill: '#6b7280', fontSize: 12 }} 
                  tickFormatter={(value) => `${value / 1000}k`}
                />
                <Tooltip content={showTrend ? <ComparisonTooltip /> : <CustomTooltip />} />
                <Legend iconType="circle" wrapperStyle={{ paddingTop: 20 }} />
                <Bar dataKey="revenue" name="Revenue" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                <Bar dataKey="expenses" name="Expenses" fill="#ef4444" radius={[4, 4, 0, 0]} />
                <Bar dataKey="profit" name="Profit" fill="#10b981" radius={[4, 4, 0, 0]} />
                {showTrend && (
                  <>
                    <Bar dataKey="prevYearRevenue" name="Previous Year Revenue" fill="#93c5fd" radius={[4, 4, 0, 0]} stackId="2" />
                    <Bar dataKey="prevYearProfit" name="Previous Year Profit" fill="#6ee7b7" radius={[4, 4, 0, 0]} stackId="2" />
                  </>
                )}
              </BarChart>
            ) : (
              <LineChart
                data={viewMode === 'monthly' ? chartData : yearlyData}
                margin={{ top: 10, right: 30, left: 0, bottom: 10 }}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                <XAxis 
                  dataKey="name" 
                  axisLine={false} 
                  tickLine={false} 
                  tick={{ fill: '#6b7280', fontSize: 12 }}
                />
                <YAxis 
                  axisLine={false} 
                  tickLine={false} 
                  tick={{ fill: '#6b7280', fontSize: 12 }} 
                  tickFormatter={(value) => `${value / 1000}k`}
                />
                <Tooltip content={showTrend ? <ComparisonTooltip /> : <CustomTooltip />} />
                <Legend iconType="circle" wrapperStyle={{ paddingTop: 20 }} />
                <Line 
                  type="monotone" 
                  dataKey="revenue" 
                  name="Revenue" 
                  stroke="#3b82f6" 
                  strokeWidth={3} 
                  dot={{ r: 4, fill: '#3b82f6' }} 
                  activeDot={{ r: 6 }} 
                />
                <Line 
                  type="monotone" 
                  dataKey="expenses" 
                  name="Expenses" 
                  stroke="#ef4444" 
                  strokeWidth={3} 
                  dot={{ r: 4, fill: '#ef4444' }} 
                  activeDot={{ r: 6 }} 
                />
                <Line 
                  type="monotone" 
                  dataKey="profit" 
                  name="Profit" 
                  stroke="#10b981" 
                  strokeWidth={3} 
                  dot={{ r: 4, fill: '#10b981' }} 
                  activeDot={{ r: 6 }} 
                />
                {showTrend && (
                  <>
                    <Line 
                      type="monotone" 
                      dataKey="prevYearRevenue" 
                      name="Previous Year Revenue" 
                      stroke="#93c5fd" 
                      strokeWidth={2} 
                      strokeDasharray="5 5"
                      dot={{ r: 3, fill: '#93c5fd' }} 
                    />
                    <Line 
                      type="monotone" 
                      dataKey="prevYearProfit" 
                      name="Previous Year Profit" 
                      stroke="#6ee7b7" 
                      strokeWidth={2} 
                      strokeDasharray="5 5"
                      dot={{ r: 3, fill: '#6ee7b7' }} 
                    />
                  </>
                )}
              </LineChart>
            )}
          </ResponsiveContainer>
        </div>
      </div>
      
      {/* Monthly data table */}
      {viewMode === 'monthly' && (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit Margin</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {paginatedEarnings.map((item, index) => (
                <tr key={index} className={index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {item.monthName}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.totalRevenue)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.totalExpenses)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.profit)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {item.totalRevenue > 0 ? 
                      ((item.profit / item.totalRevenue) * 100).toFixed(1) : 0}%
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      
      {/* Pagination */}
      {viewMode === 'monthly' && totalPages > 1 && (
        <div className="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 mt-4">
          <div className="flex flex-1 justify-between sm:hidden">
            <button
              onClick={() => setPage(page > 1 ? page - 1 : 1)}
              disabled={page === 1}
              className={`relative inline-flex items-center rounded-md px-4 py-2 text-sm font-medium ${
                page === 1 
                  ? 'bg-gray-100 text-gray-400' 
                  : 'bg-white text-gray-700 hover:bg-gray-50'
              }`}
            >
              Previous
            </button>
            <button
              onClick={() => setPage(page < totalPages ? page + 1 : totalPages)}
              disabled={page === totalPages}
              className={`relative ml-3 inline-flex items-center rounded-md px-4 py-2 text-sm font-medium ${
                page === totalPages 
                  ? 'bg-gray-100 text-gray-400' 
                  : 'bg-white text-gray-700 hover:bg-gray-50'
              }`}
            >
              Next
            </button>
          </div>
          <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
              <p className="text-sm text-gray-700">
                Showing <span className="font-medium">{(page - 1) * itemsPerPage + 1}</span> to <span className="font-medium">{Math.min(page * itemsPerPage, filteredEarnings.length)}</span> of{' '}
                <span className="font-medium">{filteredEarnings.length}</span> months
              </p>
            </div>
            <div>
              <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                <button
                  onClick={() => setPage(page > 1 ? page - 1 : 1)}
                  disabled={page === 1}
                  className={`relative inline-flex items-center rounded-l-md px-2 py-2 ${
                    page === 1 
                      ? 'bg-gray-100 text-gray-400' 
                      : 'bg-white text-gray-500 hover:bg-gray-50'
                  }`}
                >
                  <span className="sr-only">Previous</span>
                  <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clipRule="evenodd" />
                  </svg>
                </button>
                
                {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNumber) => (
                  <button
                    key={pageNumber}
                    onClick={() => setPage(pageNumber)}
                    aria-current={page === pageNumber ? "page" : undefined}
                    className={`relative inline-flex items-center px-4 py-2 text-sm font-semibold ${
                      page === pageNumber
                        ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                        : 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-offset-0'
                    }`}
                  >
                    {pageNumber}
                  </button>
                ))}
                
                <button
                  onClick={() => setPage(page < totalPages ? page + 1 : totalPages)}
                  disabled={page === totalPages}
                  className={`relative inline-flex items-center rounded-r-md px-2 py-2 ${
                    page === totalPages 
                      ? 'bg-gray-100 text-gray-400' 
                      : 'bg-white text-gray-500 hover:bg-gray-50'
                  }`}
                >
                  <span className="sr-only">Next</span>
                  <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clipRule="evenodd" />
                  </svg>
                </button>
              </nav>
            </div>
          </div>
        </div>
      )}
      
      {/* Quarterly data table (for yearly view) */}
      {viewMode === 'yearly' && (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quarter</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit Margin</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {yearlyData.map((item, index) => (
                <tr key={index} className={index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {item.name} ({selectedYear})
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.revenue)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.expenses)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {formatCurrency(item.profit)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {item.revenue > 0 ? 
                      ((item.profit / item.revenue) * 100).toFixed(1) : 0}%
                  </td>
                </tr>
              ))}
              
              {/* Yearly total row */}
              <tr className="bg-gray-100 font-medium">
                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                  Total ({selectedYear})
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                  {formatCurrency(totalRevenue)}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                  {formatCurrency(totalExpenses)}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                  {formatCurrency(totalProfit)}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                  {totalRevenue > 0 ? 
                    ((totalProfit / totalRevenue) * 100).toFixed(1) : 0}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
      
      {/* Year-over-year comparison section */}
      {previousYearEarnings.length > 0 && (
        <div className="mt-10 bg-gray-50 rounded-xl p-6 border border-gray-100">
          <h3 className="text-lg font-medium text-gray-800 mb-4">
            Year-over-Year Comparison ({selectedYear} vs {selectedYear - 1})
          </h3>
          
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
              <h4 className="text-sm font-medium text-gray-500 mb-2">Revenue Growth</h4>
              <div className="flex items-baseline">
                <p className="text-xl font-bold mr-2">
                  {revenueChange.percentage}%
                </p>
                <span className={`text-sm font-medium ${
                  revenueChange.direction === 'up' ? 'text-green-600' : 
                  revenueChange.direction === 'down' ? 'text-red-600' : 'text-gray-600'
                }`}>
                  {revenueChange.direction === 'up' ? '↑' : revenueChange.direction === 'down' ? '↓' : ''}
                </span>
              </div>
            </div>
            
            <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
              <h4 className="text-sm font-medium text-gray-500 mb-2">Expense Growth</h4>
              <div className="flex items-baseline">
                <p className="text-xl font-bold mr-2">
                  {expensesChange.percentage}%
                </p>
                <span className={`text-sm font-medium ${
                  expensesChange.direction === 'up' ? 'text-red-600' : 
                  expensesChange.direction === 'down' ? 'text-green-600' : 'text-gray-600'
                }`}>
                  {expensesChange.direction === 'up' ? '↑' : expensesChange.direction === 'down' ? '↓' : ''}
                </span>
              </div>
            </div>
            
            <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
              <h4 className="text-sm font-medium text-gray-500 mb-2">Profit Growth</h4>
              <div className="flex items-baseline">
                <p className="text-xl font-bold mr-2">
                  {profitChange.percentage}%
                </p>
                <span className={`text-sm font-medium ${
                  profitChange.direction === 'up' ? 'text-green-600' : 
                  profitChange.direction === 'down' ? 'text-red-600' : 'text-gray-600'
                }`}>
                  {profitChange.direction === 'up' ? '↑' : profitChange.direction === 'down' ? '↓' : ''}
                </span>
              </div>
            </div>
          </div>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
              <h4 className="text-sm font-medium text-gray-500 mb-3">Revenue Comparison</h4>
              <div className="flex items-center justify-between mb-2">
                <p className="text-sm font-medium text-gray-600">{selectedYear}:</p>
                <p className="text-sm font-bold text-gray-900">{formatCurrency(totalRevenue)}</p>
              </div>
              <div className="flex items-center justify-between mb-4">
                <p className="text-sm font-medium text-gray-600">{selectedYear - 1}:</p>
                <p className="text-sm font-bold text-gray-900">
                  {formatCurrency(previousYearEarnings.reduce((sum, item) => sum + Number(item.totalRevenue || 0), 0))}
                </p>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2.5">
                <div 
                  className={`h-2.5 rounded-full ${revenueChange.direction === 'up' ? 'bg-green-600' : 'bg-red-600'}`} 
                  style={{ width: `${Math.min(100, Math.abs(revenueChange.percentage))}%` }}
                ></div>
              </div>
            </div>
            
            <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
              <h4 className="text-sm font-medium text-gray-500 mb-3">Profit Comparison</h4>
              <div className="flex items-center justify-between mb-2">
                <p className="text-sm font-medium text-gray-600">{selectedYear}:</p>
                <p className="text-sm font-bold text-gray-900">{formatCurrency(totalProfit)}</p>
              </div>
              <div className="flex items-center justify-between mb-4">
                <p className="text-sm font-medium text-gray-600">{selectedYear - 1}:</p>
                <p className="text-sm font-bold text-gray-900">
                  {formatCurrency(previousYearEarnings.reduce((sum, item) => sum + Number(item.profit || 0), 0))}
                </p>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2.5">
                <div 
                  className={`h-2.5 rounded-full ${profitChange.direction === 'up' ? 'bg-green-600' : 'bg-red-600'}`} 
                  style={{ width: `${Math.min(100, Math.abs(profitChange.percentage))}%` }}
                ></div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AdminEarningsSection;