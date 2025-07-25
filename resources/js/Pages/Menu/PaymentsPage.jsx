import React, { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import PageHeader from '@/Components/PaymentsAndTransactions/PageHeader';
import PaymentsList from '@/Components/PaymentsAndTransactions/PaymentsList';
import PaymentForm from '@/Components/forms/PaymentForm';
import PaymentDetails from '@/Components/PaymentsAndTransactions/PaymentDetails';
import DashboardLayout from '@/Layouts/DashboardLayout';
import UserSelect from '@/Components/PaymentsAndTransactions/UserSelect';
import TransactionAnalytics from '@/Components/PaymentsAndTransactions/TransactionAnalytics';
import Alert from '@/Components/PaymentsAndTransactions/Alert';
import BatchPaymentForm from '@/Components/PaymentsAndTransactions/BatchPaymentForm'; // We'll create this component
import RecurringTransactionsPage from '../Payments/RecurringTransactionsPage';
import Pagination from '@/Components/Pagination';
import AdminEarningsSection from '@/Components/PaymentsAndTransactions/AdminEarningsSection';
import axios from 'axios';

const PaymentsPage = ({
  transactions,
  transaction,
  users,
  formType,
  flash,
  errors,
  teacherCount,
  assistantCount,
  totalWallet,
  totalSalary,
  recurringTransactions,
  adminEarnings
}) => {
  console.log('userrrr', users);
  console.log('transactions', transactions);
  
  // Ensure users is always an array
  const safeUsers = Array.isArray(users) ? users : [];
  
  const [showForm, setShowForm] = useState(formType ? true : false);
  const [showDetails, setShowDetails] = useState(transaction ? true : false);
  const [activeView, setActiveView] = useState(formType ? 'form' : (transaction && !formType ? 'details' : 'list'));
  const [localAdminEarnings, setLocalAdminEarnings] = useState(adminEarnings || []);

  // Fetch admin earnings data if not provided
  useEffect(() => {
    if (!adminEarnings || (adminEarnings?.earnings && adminEarnings.earnings.length === 0)) {
      axios.get(route('admin.earnings.dashboard'))
        .then(response => {
          setLocalAdminEarnings(response.data);
        })
        .catch(error => {
          console.error('Error fetching admin earnings:', error);
        });
    }
  }, [adminEarnings]);

  // Update state when props change (e.g., after navigation)
  useEffect(() => {
    setShowForm(formType ? true : false);
    setShowDetails(transaction && !formType ? true : false);
    setActiveView(formType ? 'form' : (transaction && !formType ? 'details' : 'list'));
  }, [formType, transaction]);

  const handleCreateNew = () => {
    router.get(route('transactions.create'));
  };

  const handleBatchPayment = () => {
    setActiveView('batch');
  };

  const handleCancelForm = () => {
    router.get(route('transactions.index'));
  };

  const handleViewDetails = (id) => {
    console.log('Viewing details for:', id);
    if (typeof id === 'object' && id.transactions) {
      // If we received an employee object with transactions, navigate to employee transactions
      router.get(route('employees.transactions', { employee: id.userId }));
    } else {
      // If we received a transaction ID, show that specific transaction
      router.get(route('transactions.show', { transaction: id }));
    }
  };

  const handleEditTransaction = (id) => {
    console.log('Editing transaction:', id);
    router.get(route('transactions.edit', id));
  };

  const handleDeleteTransaction = (id) => {
    console.log('Attempting to delete transaction:', id);
    if (confirm('Are you sure you want to delete this transaction?')) {
      router.delete(route('transactions.destroy', id));
    }
  };

  // New handlers for EmployeeSummaryTable
  const handleMakePayment = (employeeId, balance, employeeData) => {
    console.log('Making payment for employee:', employeeId, 'with balance:', balance);
    // Prepare the form data for a new payment transaction
    const formData = {
      user_id: employeeId,
      type: employeeData.role === 'teacher' ? 'payment' : 'salary',
      amount: balance > 0 ? balance : 0, // Pre-fill with the outstanding balance amount if positive
      payment_date: new Date().toISOString().split('T')[0], // Today's date
      description: `${employeeData.role === 'teacher' ? 'Payment' : 'Salary payment'} for ${employeeData.userName}`,
      is_recurring: false
    };

    console.log('Navigating to create transaction with form data:', formData);
    // Navigate to the create transaction form with pre-filled data
    router.get(route('transactions.create'), formData);
  };

  const handleEditEmployee = (transactionId) => {
    console.log('Editing transaction:', transactionId);
    if (!transactionId) {
      console.error('No transaction ID provided');
      return;
    }
    
    // Navigate to the transaction edit form
    router.get(route('transactions.edit', transactionId));
  };

  const handleSubmit = (formData, id = null) => {
    if (formType === 'edit' && id) {
      router.put(route('transactions.update', id), formData);
    } else {
      router.post(route('transactions.store'), formData);
    }
  };

  const handleBatchSubmit = (formData) => {
    router.post(route('transactions.batch-pay'), formData);
  };

  const handleProcessRecurring = () => {
    setActiveView('recurring');
    router.get(route('transactions.recurring'));
  };

  return (
    <div className="py-6">
      <div className="mx-20 sm:px-6 lg:px-8">
        <PageHeader
          title="Payment Management"
          description="View, create and manage all your financial transactions"
        >
          {activeView !== 'form' && activeView !== 'batch' && (
            <div className="flex space-x-2">
              <button
                onClick={handleCreateNew}
                className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                Add New Transaction
              </button>
              <button
                onClick={handleBatchPayment}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
              >
                Batch Payment
              </button>
              <button
                onClick={handleProcessRecurring}
                className="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500"
              >
                Process Recurring
              </button>
            </div>
          )}
          {(activeView === 'form' || activeView === 'batch') && (
            <button
              onClick={handleCancelForm}
              className="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400"
            >
              Cancel
            </button>
          )}
        </PageHeader>
        {flash?.success && <Alert type="success" message={flash.success} className="mb-6" />}
        <div className="bg-white h-full shadow-sm sm:rounded-lg">
          <div className="p-6 bg-white border-b border-gray-200">
            {activeView === 'list' && (
              <PaymentsList
                transactions={transactions}
                onView={handleViewDetails}
                onEdit={handleEditTransaction}
                onDelete={handleDeleteTransaction}
                users={safeUsers}
                onMakePayment={handleMakePayment}
                onEditEmployee={handleEditEmployee}
                adminEarnings={localAdminEarnings}
              />
            )}
            {activeView === 'form' && (
              <PaymentForm
                transaction={transaction}
                transactions={transactions.data}
                errors={errors}
                formType={formType || 'create'}
                onCancel={handleCancelForm}
                onSubmit={handleSubmit}
                users={safeUsers}
              />
            )}
            {activeView === 'details' && transaction && (
              <PaymentDetails
                transaction={transaction}
                onEdit={() => handleEditTransaction(transaction.id)}
                onBack={() => {
                  router.get(route('transactions.index'));
                }}
              />
            )}
            {activeView === 'batch' && (
              <BatchPaymentForm
                teacherCount={teacherCount}
                assistantCount={assistantCount}
                totalWallet={totalWallet}
                totalSalary={totalSalary}
                transactions={transactions.data}
                errors={errors}
                onCancel={handleCancelForm}
                onSubmit={handleBatchSubmit}
                users={safeUsers}
              />
            )}
            {activeView === 'recurring' && (
              <RecurringTransactionsPage
                recurringTransactions={recurringTransactions}
                flash={flash}
                errors={errors}
                isEmbedded={true}
              />
            )}
          </div>
        </div>
        <Pagination links={transactions.links} />
        {localAdminEarnings && <AdminEarningsSection adminEarnings={localAdminEarnings} />}
        {/* <UserSelect users={users}/> */}
        {/* <TransactionAnalytics transactions={transactions} /> */}
      </div>
    </div>
  );
};

PaymentsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default PaymentsPage;