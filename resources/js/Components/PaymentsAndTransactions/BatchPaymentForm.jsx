import React, { useState, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { format, addMonths, addYears } from 'date-fns';

const BatchPaymentForm = ({ 
  teacherCount = 0, 
  assistantCount = 0, 
  totalWallet = 0, 
  totalSalary = 0,
  errors = {},
  onCancel,
  onSubmit,
  processing = false
}) => {
  const [showRecurring, setShowRecurring] = useState(false);
  const [previewAmount, setPreviewAmount] = useState(totalWallet + totalSalary);
  
  const { data, setData, reset } = useForm({
    role: 'all',
    payment_date: format(new Date(), 'yyyy-MM-dd'),
    description: 'Monthly payment',
    is_recurring: false,
    frequency: 'monthly',
    next_payment_date: format(addMonths(new Date(), 1), 'yyyy-MM-dd'),
  });

  // Update next payment date automatically when frequency changes
  useEffect(() => {
    if (data.frequency === 'monthly') {
      setData('next_payment_date', format(addMonths(new Date(data.payment_date), 1), 'yyyy-MM-dd'));
    } else if (data.frequency === 'yearly') {
      setData('next_payment_date', format(addYears(new Date(data.payment_date), 1), 'yyyy-MM-dd'));
    }
  }, [data.frequency, data.payment_date]);

  // Update preview amount when role changes
  useEffect(() => {
    if (data.role === 'all') {
      setPreviewAmount(totalWallet + totalSalary);
    } else if (data.role === 'teacher') {
      setPreviewAmount(totalWallet);
    } else {
      setPreviewAmount(totalSalary);
    }
  }, [data.role, totalWallet, totalSalary]);

  const handleSubmit = (e) => {
    e.preventDefault();
    onSubmit(data);
  };

  const handleReset = () => {
    reset();
    setShowRecurring(false);
  };

  // Format currency with DH
  const formatCurrency = (amount) => {
    return `${amount.toFixed(2)} DH`;
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6">
      <h2 className="text-xl font-bold text-gray-800 mb-6 border-b pb-2">Batch Payment Management</h2>
      
      <div className="mb-6">
        <h3 className="text-lg font-medium text-gray-700 mb-3">Payment Summary</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="bg-blue-50 p-4 rounded-lg border border-blue-100 transition-all hover:shadow-md">
            <div className="flex justify-between items-center mb-3">
              <span className="text-sm font-medium text-blue-700">Teachers</span>
              <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-semibold">{teacherCount}</span>
            </div>
            <div className="flex justify-between items-center">
              <span className="text-sm font-medium text-blue-700">Total Wallet</span>
              <span className="text-lg font-bold text-blue-800">{formatCurrency(totalWallet)}</span>
            </div>
          </div>
          <div className="bg-green-50 p-4 rounded-lg border border-green-100 transition-all hover:shadow-md">
            <div className="flex justify-between items-center mb-3">
              <span className="text-sm font-medium text-green-700">Assistants</span>
              <span className="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-semibold">{assistantCount}</span>
            </div>
            <div className="flex justify-between items-center">
              <span className="text-sm font-medium text-green-700">Total Salary</span>
              <span className="text-lg font-bold text-green-800">{formatCurrency(totalSalary)}</span>
            </div>
          </div>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label htmlFor="role" className="block font-medium text-sm text-gray-700 mb-1">
              Employee Role
            </label>
            <select
              id="role"
              name="role"
              value={data.role}
              onChange={(e) => setData('role', e.target.value)}
              className="w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
            >
              <option value="all">All Employees ({teacherCount + assistantCount})</option>
              <option value="teacher">Teachers Only ({teacherCount})</option>
              <option value="assistant">Assistants Only ({assistantCount})</option>
            </select>
            {errors?.role && <div className="text-red-500 text-xs mt-1">{errors.role}</div>}
          </div>

          <div>
            <label htmlFor="payment_date" className="block font-medium text-sm text-gray-700 mb-1">
              Payment Date
            </label>
            <input
              type="date"
              id="payment_date"
              name="payment_date"
              value={data.payment_date}
              onChange={(e) => setData('payment_date', e.target.value)}
              className="w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
            />
            {errors?.payment_date && <div className="text-red-500 text-xs mt-1">{errors.payment_date}</div>}
          </div>
        </div>

        <div>
          <label htmlFor="description" className="block font-medium text-sm text-gray-700 mb-1">
            Description
          </label>
          <textarea
            id="description"
            name="description"
            value={data.description}
            onChange={(e) => setData('description', e.target.value)}
            rows="2"
            placeholder="Enter payment description..."
            className="w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
          />
          {errors?.description && <div className="text-red-500 text-xs mt-1">{errors.description}</div>}
        </div>

        <div className="bg-gray-50 p-4 rounded-lg border border-gray-200">
          <div className="flex items-center">
            <input
              type="checkbox"
              id="is_recurring"
              name="is_recurring"
              checked={data.is_recurring}
              onChange={(e) => {
                setData('is_recurring', e.target.checked);
                setShowRecurring(e.target.checked);
              }}
              className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
            />
            <label htmlFor="is_recurring" className="ml-2 block text-sm font-medium text-gray-700">
              Set as Recurring Payment
            </label>
            {errors?.is_recurring && <div className="text-red-500 text-xs mt-1">{errors.is_recurring}</div>}
          </div>

          {showRecurring && (
            <div className="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4 pl-6 border-l-2 border-blue-200">
              <div>
                <label htmlFor="frequency" className="block font-medium text-sm text-gray-700 mb-1">
                  Frequency
                </label>
                <select
                  id="frequency"
                  name="frequency"
                  value={data.frequency}
                  onChange={(e) => setData('frequency', e.target.value)}
                  className="w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                >
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly</option>
                  <option value="custom">Custom</option>
                </select>
                {errors?.frequency && <div className="text-red-500 text-xs mt-1">{errors.frequency}</div>}
              </div>

              <div>
                <label htmlFor="next_payment_date" className="block font-medium text-sm text-gray-700 mb-1">
                  Next Payment Date
                </label>
                <input
                  type="date"
                  id="next_payment_date"
                  name="next_payment_date"
                  value={data.next_payment_date}
                  onChange={(e) => setData('next_payment_date', e.target.value)}
                  className="w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                />
                {errors?.next_payment_date && <div className="text-red-500 text-xs mt-1">{errors.next_payment_date}</div>}
              </div>
            </div>
          )}
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-between mt-6 pt-4 border-t border-gray-200">
          <div className="bg-gray-100 p-3 rounded-lg mb-4 sm:mb-0 w-full sm:w-auto">
            <div className="text-sm text-gray-500">Total amount to be paid:</div>
            <div className="text-xl font-bold text-gray-800">{formatCurrency(previewAmount)}</div>
          </div>
          <div className="flex gap-2 w-full sm:w-auto">
            <button
              type="button"
              onClick={handleReset}
              className="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors"
            >
              Reset
            </button>
            <button
              type="button"
              onClick={onCancel}
              className="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={processing}
              className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors flex items-center gap-2"
            >
              {processing ? (
                <>
                  <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Processing...
                </>
              ) : (
                'Process Payments'
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default BatchPaymentForm;