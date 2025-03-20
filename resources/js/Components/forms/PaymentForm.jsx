import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import TransactionDetails from '../PaymentsAndTransactions/TransactionDetails';
import RecurringPaymentSettings from '../PaymentsAndTransactions/RecurringPaymentSettings';

const PaymentForm = ({ transaction = null, errors = {}, formType, onCancel, users }) => {
  const { auth } = usePage().props;
  
  const [values, setValues] = useState({
    type: transaction?.type || 'salary',
    user_id: transaction?.user_id || 0,
    amount: transaction?.amount || '',
    description: transaction?.description || '',
    payment_date: transaction?.payment_date ? new Date(transaction.payment_date) : new Date(),
    is_recurring: transaction?.is_recurring || false,
    frequency: transaction?.frequency || 'monthly',
    next_payment_date: transaction?.next_payment_date ? new Date(transaction.next_payment_date) : '',
    category: transaction?.category || 'other',
    user_name: transaction?.user_name || '',
    rest: transaction?.rest || 0,
    custom_category: '',
    // Add full_salary to keep track of the original salary amount
    full_salary: transaction?.full_salary || 0
  });
  
  const [filteredUsers, setFilteredUsers] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [showCustomCategory, setShowCustomCategory] = useState(values.category === 'other');

  // Filter users based on transaction type
  useEffect(() => {
    if (users && users.length > 0) {
      let filtered = users;
      
      // Filter by transaction type - only for salary type
      if (values.type === 'salary') {
        filtered = users.filter(user => 
          user.role === 'teacher' ||
          user.role === 'assistant' ||
          user.role === 'admin' ||
          user.role === 'staff'
        );
      }
      
      setFilteredUsers(filtered);
      
      // If we have a selected user ID, find the user object
      if (values.user_id) {
        const user = filtered.find(u => u.id === values.user_id);
        setSelectedUser(user || null);
        
        // If user is found, set the amount based on role
        if (user) {
          // Get the full salary amount
          let fullSalary = '';
          if (user.role === 'teacher' && user.wallet) {
            fullSalary = user.wallet;
          } else if ((user.role === 'assistant' || user.role === 'admin' || user.role === 'staff') && user.salary) {
            fullSalary = user.salary;
          }
          
          // Set initial amount to empty string - admin will input this
          setValues(prevValues => ({
            ...prevValues,
            amount: '',
            full_salary: fullSalary,
            rest: fullSalary // Initially rest is full salary (nothing paid yet)
          }));
        }
      }
    }
  }, [values.type, users, values.user_id]);

  // Get default description based on transaction type
  const getDefaultDescription = (type, userName = '') => {
    switch (type) {
      case 'salary':
        return `Salary payment for ${userName || 'staff member'}`;
      case 'expense':
        return 'School expense';
      default:
        return 'School expense';
    }
  };

  // Handle form field changes
  const handleChange = (e) => {
    const key = e.target.id || e.target.name;
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    
    // Check if this is the category changing to/from 'other'
    if (key === 'category') {
      setShowCustomCategory(value === 'other');
    }
    
    setValues(values => {
      const newValues = {
        ...values,
        [key]: value,
      };
      
      // If amount is changed, recalculate rest when in salary mode
      if (key === 'amount' && values.type === 'salary') {
        const paymentAmount = parseFloat(value) || 0;
        const fullSalary = parseFloat(values.full_salary) || 0;
        
        // Calculate remaining balance
        // For new payments, rest = full salary - amount
        // For existing payments, we might need a different calculation
        newValues.rest = Math.max(0, fullSalary - paymentAmount).toFixed(2);
      }
      
      return newValues;
    });
  };

  // Handle transaction type change
  const handleTypeChange = (e) => {
    const newType = e.target.value;
    const userName = selectedUser ? selectedUser.name : '';
    
    setValues(prevValues => ({
      ...prevValues,
      type: newType,
      user_id: newType === 'salary' ? 0 : (newType === 'expense' ? auth.user.id : prevValues.user_id),
      description: getDefaultDescription(newType, userName),
      user_name: newType === 'salary' ? '' : prevValues.user_name,
      // Reset amount and rest when changing transaction type
      amount: '',
      rest: 0,
      full_salary: 0
    }));
    
    // Reset selected user if switching from salary to expense
    if (newType !== 'salary') {
      setSelectedUser(null);
    }
  };

  // Handle date changes
  const handleDateChange = (field, date) => {
    setValues(values => ({
      ...values,
      [field]: date,
    }));
  };

  // Handle user selection
  const handleUserSelect = (userId) => {
    const user = users.find(u => u.id === parseInt(userId));
    
    if (user) {
      setSelectedUser(user);
      
      // Determine full salary based on user role
      let fullSalary = 0;
      
      if (user.role === 'teacher' && user.wallet) {
        fullSalary = parseFloat(user.wallet) || 0;
      } else if ((user.role === 'assistant' || user.role === 'admin' || user.role === 'staff') && user.salary) {
        fullSalary = parseFloat(user.salary) || 0;
      }
      
      setValues(values => ({
        ...values,
        user_id: parseInt(userId),
        user_name: user.name || '',
        description: getDefaultDescription(values.type, user.name || ''),
        // Leave amount empty for admin to input
        amount: '',
        full_salary: fullSalary,
        rest: fullSalary.toFixed(2) // Initially rest equals full salary
      }));
    }
  };

  // Handle form submission
  const handleSubmit = (e) => {
    e.preventDefault();
    
    // Format dates for submission
    const formattedValues = {
      ...values,
      payment_date: values.payment_date ? values.payment_date.toISOString().split('T')[0] : null,
      next_payment_date: values.next_payment_date ? values.next_payment_date.toISOString().split('T')[0] : null,
      // Set the category to custom value if "other" is selected
      category: values.category === 'other' && values.custom_category ? values.custom_category : values.category,
    };
    
    console.log('formattedValues', formattedValues);
    if (formType === 'edit' && transaction) {
      router.put(route('transactions.update', transaction.id), formattedValues);
    } else {
      router.post(route('transactions.store'), formattedValues);
    }
  };

  // Check if user selection is required
  const isUserSelectionRequired = values.type === 'salary';

  return (
    <div className="bg-white rounded-lg shadow">
      <div className="p-6">
        <div className="mb-6">
          <h2 className="text-2xl font-semibold text-gray-800">
            {formType === 'edit' ? 'Edit Payment Transaction' : 'Create New Payment Transaction'}
          </h2>
          <p className="text-gray-600 mt-1">
            {formType === 'edit' 
              ? 'Update the details of an existing payment or expense'
              : 'Enter the details to record a new payment or expense'}
          </p>
        </div>
        
        <form onSubmit={handleSubmit} className="space-y-6">
          <TransactionDetails 
            values={values}
            errors={errors}
            handleChange={handleChange}
            handleTypeChange={handleTypeChange}
            handleDateChange={handleDateChange}
            handleUserSelect={handleUserSelect}
            showCustomCategory={showCustomCategory}
            filteredUsers={filteredUsers}
            selectedUser={selectedUser}
            auth={auth}
          />
          
          <RecurringPaymentSettings 
            values={values}
            errors={errors}
            handleChange={handleChange}
            handleDateChange={handleDateChange}
          />
          
          {/* Form Actions */}
          <div className="flex justify-end space-x-3 pt-4 border-t border-gray-200 mt-6">
            <button
              type="button"
              onClick={onCancel}
              className="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >
              Cancel
            </button>
            <button
              type="submit"
              className={`py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                isUserSelectionRequired && !values.user_id
                  ? 'bg-indigo-300 cursor-not-allowed'
                  : 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500'
              }`}
              disabled={isUserSelectionRequired && !values.user_id}
            >
              {formType === 'edit' ? 'Update Transaction' : 'Create Transaction'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default PaymentForm;