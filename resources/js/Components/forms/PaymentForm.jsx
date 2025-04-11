import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import TransactionDetails from '../PaymentsAndTransactions/TransactionDetails';
import RecurringPaymentSettings from '../PaymentsAndTransactions/RecurringPaymentSettings';
import { format } from 'date-fns';

// Define the formatCurrency function if it doesn't exist
const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2
  }).format(amount);
};

const PaymentForm = ({ transaction = null, errors = {}, formType, onCancel, users }) => {
  const { auth, transactions = [] } = usePage().props;
  
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
  const [alreadyPaidInfo, setAlreadyPaidInfo] = useState(null);

  // Check if a payment already exists for the current month
  const checkExistingPayment = () => {
    if (!transactions || transactions.length === 0) return null;
    
    const currentDate = new Date(values.payment_date);
    const currentMonth = currentDate.getMonth();
    const currentYear = currentDate.getFullYear();
    
    // Find payments for the same user/category in the current month
    const existingPayments = transactions.filter(t => {
      const paymentDate = new Date(t.payment_date);
      const sameMonth = paymentDate.getMonth() === currentMonth && paymentDate.getFullYear() === currentYear;
      
      // Filter based on type
      if (values.type === 'salary' || values.type === 'wallet' || values.type === 'payment') {
        return sameMonth && t.user_id === values.user_id && t.type === values.type && t.id !== (transaction?.id || 0);
      } else if (values.type === 'expense') {
        return sameMonth && t.category === values.category && t.type === 'expense' && t.id !== (transaction?.id || 0);
      }
      
      return false;
    });
    
    if (existingPayments.length > 0) {
      // Return info about the most recent payment
      const latestPayment = existingPayments.sort((a, b) => 
        new Date(b.payment_date) - new Date(a.payment_date)
      )[0];
      
      // Calculate total amount already paid this month
      const totalPaid = existingPayments.reduce((sum, payment) => sum + parseFloat(payment.amount || 0), 0);
      
      // Check specific conditions for different user types
      let shouldShowWarning = false;
      let warningType = 'already_paid';
      
      if (values.type === 'salary') {
        if (selectedUser?.role === 'teacher') {
          // For teachers: Show warning only if they have wallet > 0 and are fully paid
          const walletAmount = parseFloat(selectedUser?.wallet || selectedUser?.teacher?.wallet || 0);
          shouldShowWarning = walletAmount > 0 && totalPaid >= walletAmount;
        } else if (selectedUser?.role === 'assistant' || selectedUser?.role === 'admin' || selectedUser?.role === 'staff') {
          // For assistants/staff: Show warning if total paid matches salary
          const salaryAmount = parseFloat(selectedUser?.salary || 0);
          if (totalPaid >= salaryAmount) {
            shouldShowWarning = true;
          } else {
            // If partially paid, show a different message
            shouldShowWarning = totalPaid > 0;
            warningType = 'partially_paid';
          }
        }
      } else if (values.type === 'expense') {
        // For expenses, always show warning if same category was paid this month
        shouldShowWarning = true;
      }
      
      if (shouldShowWarning) {
        return {
          date: format(new Date(latestPayment.payment_date), 'MMM dd, yyyy'),
          amount: latestPayment.amount,
          totalPaid: totalPaid,
          type: latestPayment.type,
          warningType: warningType,
          recipient: latestPayment.user_name || (latestPayment.user ? latestPayment.user.name : 'Unknown'),
          fullSalary: selectedUser ? (selectedUser.role === 'teacher' ? 
            parseFloat(selectedUser.wallet || selectedUser.teacher?.wallet || 0) : 
            parseFloat(selectedUser.salary || 0)) : 0
        };
      }
    }
    
    return null;
  };

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
  
  // Check for existing payments when relevant values change
  useEffect(() => {
    // Only check for existing payments when we have enough information
    if ((values.type === 'salary' && values.user_id) || 
        (values.type === 'expense' && values.category)) {
      const existingPayment = checkExistingPayment();
      setAlreadyPaidInfo(existingPayment);
    } else {
      setAlreadyPaidInfo(null);
    }
  }, [values.type, values.user_id, values.category, values.payment_date]);

  // Get default description based on transaction type
  const getDefaultDescription = (type, userName = '') => {
    switch (type) {
      case 'salary':
        return `Salary payment for ${userName || 'staff member'}`;
      case 'wallet':
        return `Adding funds to wallet for ${userName || 'teacher'}`;
      case 'payment':
        return `Payment from wallet for ${userName || 'teacher'}`;
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
      user_id: newType === 'salary' || newType === 'wallet' || newType === 'payment' ? 0 : (newType === 'expense' ? auth.user.id : prevValues.user_id),
      description: getDefaultDescription(newType, userName),
      user_name: newType === 'salary' || newType === 'wallet' || newType === 'payment' ? '' : prevValues.user_name,
      // Reset amount and rest when changing transaction type
      amount: '',
      rest: 0,
      full_salary: 0
    }));
    
    // Reset selected user if switching from salary to expense
    if (newType === 'expense') {
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
      
      // Determine full salary based on user role and transaction type
      let fullSalary = 0;
      let defaultAmount = '';
      
      if (user.role === 'teacher') {
        if (user.wallet || user.teacher?.wallet) {
          const walletBalance = parseFloat(user.wallet || user.teacher?.wallet) || 0;
          fullSalary = walletBalance;
          
          // For payment type, set the default amount to the wallet balance
          if (values.type === 'payment') {
            defaultAmount = walletBalance.toString();
          }
        }
      } else if ((user.role === 'assistant' || user.role === 'admin' || user.role === 'staff') && user.salary) {
        fullSalary = parseFloat(user.salary) || 0;
      }
      
      setValues(values => ({
        ...values,
        user_id: parseInt(userId),
        user_name: user.name || '',
        description: getDefaultDescription(values.type, user.name || ''),
        // Use default amount if set, otherwise leave empty
        amount: defaultAmount,
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
        
        {/* Payment Already Exists Message */}
        {alreadyPaidInfo && (
          <div className={`mb-6 p-4 rounded-md ${
            alreadyPaidInfo.warningType === 'already_paid' 
              ? 'bg-amber-50 border-l-4 border-amber-500' 
              : 'bg-blue-50 border-l-4 border-blue-500'
          }`}>
            <div className="flex items-start">
              <div className="flex-shrink-0 mt-0.5">
                <svg className={`h-5 w-5 ${
                  alreadyPaidInfo.warningType === 'already_paid' ? 'text-amber-600' : 'text-blue-600'
                }`} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
              </div>
              <div className="ml-3">
                {alreadyPaidInfo.warningType === 'already_paid' ? (
                  <>
                    <h3 className="text-sm font-medium text-amber-800">Payment Already Completed for Current Month</h3>
                    <div className="mt-1 text-sm text-amber-700">
                      {values.type === 'salary' || values.type === 'wallet' || values.type === 'payment' ? (
                        <p>
                          <span className="font-medium">{alreadyPaidInfo.recipient}</span> has already received their full payment 
                          of <span className="font-medium">{formatCurrency(alreadyPaidInfo.fullSalary)}</span> for this month 
                          (last paid on {alreadyPaidInfo.date}).
                        </p>
                      ) : (
                        <p>An expense in category "<span className="font-medium">{values.category}</span>" was already paid this month 
                          ({formatCurrency(alreadyPaidInfo.amount)} on {alreadyPaidInfo.date}).</p>
                      )}
                    </div>
                  </>
                ) : (
                  <>
                    <h3 className="text-sm font-medium text-blue-800">Partial Payment Already Made This Month</h3>
                    <div className="mt-1 text-sm text-blue-700">
                      <p>
                        <span className="font-medium">{alreadyPaidInfo.recipient}</span> has already been paid 
                        <span className="font-medium"> {formatCurrency(alreadyPaidInfo.totalPaid)}</span> of their 
                        <span className="font-medium"> {formatCurrency(alreadyPaidInfo.fullSalary)}</span> salary this month.
                      </p>
                      <p className="mt-1">
                        Remaining to be paid: <span className="font-medium">{formatCurrency(Math.max(0, alreadyPaidInfo.fullSalary - alreadyPaidInfo.totalPaid))}</span>
                      </p>
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>
        )}
        
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
          
          <div className="mt-4">
            <label className="block text-sm font-medium text-gray-700">Payment Type</label>
            <select
              value={values.type}
              onChange={handleTypeChange}
              className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            >
              <option value="">Select type</option>
              <option value="salary">Salary Payment</option>
              <option value="wallet">Add to Wallet (Teachers)</option>
              <option value="payment">Payment from Wallet (Teachers)</option>
              <option value="expense">Expense</option>
            </select>
            {values.type === 'payment' && selectedUser?.role === 'teacher' && (
              <p className="mt-1 text-sm text-amber-600">
                Note: This will deduct the amount from the teacher's wallet balance.
                {selectedUser?.teacher && (
                  <span className="font-medium"> Current wallet balance: {formatCurrency(selectedUser.teacher.wallet || 0)}</span>
                )}
              </p>
            )}
            {errors.type && <div className="text-red-500 mt-1 text-sm">{errors.type}</div>}
          </div>
          
          {/* Form Actions */}
          <div className="flex justify-end space-x-3 pt-5 border-t border-gray-200 mt-8">
            <button
              type="button"
              className="px-4 py-2 bg-white text-gray-700 rounded-md border border-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              onClick={onCancel}
            >
              Cancel
            </button>
            <button
              type="submit"
              className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
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