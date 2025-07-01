import React, { useEffect } from 'react';
import InputError from '@/Components/InputError';
import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';

import UserSelect from '../PaymentsAndTransactions/UserSelect';

const TransactionDetails = ({
  values,
  errors,
  handleChange,
  handleTypeChange,
  handleDateChange,
  handleUserSelect,
  showCustomCategory,
  filteredUsers,
  selectedUser
}) => {
  // Debug log values
  useEffect(() => {
    console.log('TransactionDetails values updated:', values);
  }, [values]);

  // School-specific expense categories
  const expenseCategories = [
    { value: 'classroom', label: 'Classroom Materials' },
    { value: 'office', label: 'Office Supplies' },
    { value: 'sports', label: 'Sports Equipment' },
    { value: 'technology', label: 'Technology' },
    { value: 'library', label: 'Library Resources' },
    { value: 'internet', label: 'Internet/WiFi' },
    { value: 'utilities', label: 'Utilities' },
    { value: 'maintenance', label: 'Maintenance' },
    { value: 'travel', label: 'Travel/Field Trips' },
    { value: 'training', label: 'Professional Development' },
    { value: 'software', label: 'Educational Software' },
    { value: 'other', label: 'Other' },
  ];

  // Check if we should show the user selector
  const showUserSelection = values.type === 'salary' || values.type === 'payment';

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
      {/* Transaction Type selection has been removed - it's now determined automatically based on user role */}
      
      {/* Category Selection - Show for expenses */}
      {values.type === 'expense' && (
        <div>
          <label htmlFor="category" className="block text-sm font-medium text-gray-700 mb-1">
            Expense Category
          </label>
          <select
            id="category"
            name="category"
            value={values.category}
            onChange={handleChange}
            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          >
            {expenseCategories.map((category) => (
              <option key={category.value} value={category.value}>
                {category.label}
              </option>
            ))}
          </select>
          {errors.category && <InputError message={errors.category} className="mt-2" />}
        </div>
      )}
      
      {/* Custom Category Input - Show when "Other" is selected for expense */}
      {values.type === 'expense' && showCustomCategory && (
        <div>
          <label htmlFor="custom_category" className="block text-sm font-medium text-gray-700 mb-1">
            Custom Category Name
          </label>
          <input
            type="text"
            id="custom_category"
            name="custom_category"
            value={values.custom_category}
            onChange={handleChange}
            placeholder="Enter custom category"
            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          />
        </div>
      )}
      
      {/* User Selection - Only show for staff payments */}
      {showUserSelection && (
        <div className={!values.user_id ? "md:col-span-2" : ""}>
          <label htmlFor="user_id" className="block text-sm font-medium text-gray-700 mb-1">
            Staff Member
          </label>
          <UserSelect 
            users={filteredUsers}
            selectedUserId={values.user_id || null}
            onChange={(e) => handleUserSelect(e.target.value)}
            error={errors.user_id}
          />
          {selectedUser && (
            <div className="mt-2 text-sm bg-blue-50 p-2 rounded">
              <div className="flex justify-between">
                <div>
                  <span className="font-medium">Selected: </span>
                  {selectedUser.name} ({selectedUser.role})
                  {selectedUser.email && ` - ${selectedUser.email}`}
                </div>
                <div className="font-medium text-blue-600">
                  {selectedUser.role === 'teacher' ? 'Payment from Wallet' : 'Salary Payment'}
                </div>
              </div>
              {values.full_salary > 0 && (
                <div className="mt-1">
                  <span className="font-medium">
                    {selectedUser.role === 'teacher' ? 'Available in Wallet:' : 'Full Salary:'}
                  </span>
                  DH {parseFloat(values.full_salary).toFixed(2)}
                </div>
              )}
            </div>
          )}
        </div>
      )}
      
      {/* Amount */}
      <div>
        <label htmlFor="amount" className="block text-sm font-medium text-gray-700 mb-1">
          {values.type === 'salary' ? 'Amount to Pay' : 'Amount'}
        </label>
        <div className="mt-1 relative rounded-md shadow-sm">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <span className="text-gray-500 sm:text-sm">DH</span>
          </div>
          <input
            type="number"
            step="0.01"
            min="0"
            id="amount"
            name="amount"
            value={values.amount}
            onChange={handleChange}
            className="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            placeholder="0.00"
            required
          />
        </div>
        {errors.amount && <InputError message={errors.amount} className="mt-2" />}
      </div>
      
      {/* Rest Amount (if applicable) */}
      {values.type === 'salary' && (
        <div>
          <label htmlFor="rest" className="block text-sm font-medium text-gray-700 mb-1">
            Remaining Balance
          </label>
          <div className="mt-1 relative rounded-md shadow-sm">
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <span className="text-gray-500 sm:text-sm">DH</span>
            </div>
            <input
              type="number"
              step="0.01"
              min="0"
              id="rest"
              name="rest"
              value={values.rest}
              onChange={handleChange}
              className="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-gray-50"
              placeholder="0.00"
              readOnly
            />
          </div>
          <p className="mt-1 text-xs text-gray-500">
            Automatically calculated (Full Salary - Amount to Pay)
          </p>
          {errors.rest && <InputError message={errors.rest} className="mt-2" />}
        </div>
      )}
      
      {/* Payment Date */}
      <div>
        <label htmlFor="payment_date" className="block text-sm font-medium text-gray-700 mb-1">
          Payment Date
        </label>
        <DatePicker
          minDate={new Date("2025-01-01")}
          id="payment_date"
          name="payment_date"
          selected={values.payment_date}
          onChange={(date) => handleDateChange('payment_date', date)}
          className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          dateFormat="yyyy-MM-dd"
          required
        />
        {errors.payment_date && <InputError message={errors.payment_date} className="mt-2" />}
      </div>
      
      {/* Description */}
      <div className="md:col-span-2">
        <label htmlFor="description" className="block text-sm font-medium text-gray-700 mb-1">
          Description
        </label>
        <textarea
          id="description"
          name="description"
          rows="2"
          value={values.description}
          onChange={handleChange}
          className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          placeholder="Enter transaction details"
          required
        ></textarea>
        {errors.description && <InputError message={errors.description} className="mt-2" />}
      </div>
    </div>
  );
};

export default TransactionDetails;