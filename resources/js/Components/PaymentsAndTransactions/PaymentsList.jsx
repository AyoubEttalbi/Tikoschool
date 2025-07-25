import React, { useState, useMemo } from 'react';
import { Tab } from '@headlessui/react';
import EmployeeSummaryTable from './EmployeeSummaryTable';
import AllTransactionsTable from './AllTransactionsTable';
import Pagination from '@/Components/Pagination';
import AdminEarningsSection from './AdminEarningsSection';

const PaymentsList = ({
  transactions = [],
  adminEarnings = [],
  users = [],
  onView,
  onEdit,
  onDelete,
  onMakePayment,
  onAddExpense,
  onEditEmployee,
  onDeleteEmployee
}) => {
  console.log('Raw transactions:', transactions);
  console.log('Raw users:', users);
  const [filterType, setFilterType] = useState('all');
  const [selectedTab, setSelectedTab] = useState(0);

  // Ensure users is an array
  const safeUsers = Array.isArray(users) ? users : [];

  const groupedTransactions = useMemo(() => {
    if (!transactions.data) return [];
    
    // First pass: Create user entries and collect all transactions
    const transactionsByUser = transactions.data.reduce((acc, transaction) => {
      const userId = transaction.user_id;
      console.log('Processing transaction:', transaction);
      console.log('User ID:', userId);
      
      if (!acc[userId]) {
        // Find the user from the users array to get their role and salary
        const userInfo = safeUsers.find(user => user.id === userId || user.userId === userId) || {};
        console.log('Found user info:', userInfo);
        
        acc[userId] = {
          userId: userId,
          id: userId, // Add this for compatibility
          userName: transaction.user?.name || transaction.user_name || 'Unknown',
          email: transaction.user?.email || userInfo.email || '',
          role: transaction.user?.role || userInfo.role || '',
          baseSalary: parseFloat(userInfo.salary || 0),
          totalOwed: 0,
          totalPaid: 0,
          totalExpenses: 0,
          netBalance: 0,
          transactions: [],
          wallet: userInfo.wallet || 0
        };
        console.log('Created new user entry:', acc[userId]);
      }
      
      // Add transaction to the user's transactions array
      acc[userId].transactions.push(transaction);
      
      // Track expenses
      if (transaction.type === 'expense') {
        acc[userId].totalExpenses += parseFloat(transaction.amount);
      }
      
      return acc;
    }, {});
    
    console.log('Grouped transactions by user:', transactionsByUser);
    
    // Second pass: Calculate totalOwed and totalPaid based on collected transactions
    Object.values(transactionsByUser).forEach(userData => {
      // Set totalOwed based on role
      if (userData.role === 'assistant') {
        userData.totalOwed = userData.baseSalary;
      } else if (userData.role === 'teacher') {
        userData.totalOwed = userData.wallet;
      }
      
      // Calculate totalPaid from salary transactions for all users
      userData.totalPaid = userData.transactions
        .filter(t => t.type === 'salary')
        .reduce((sum, t) => sum + parseFloat(t.amount), 0);
      
      // Calculate net balance
      userData.netBalance = userData.totalOwed - userData.totalPaid;
      
      console.log('Updated user data:', userData);
    });
    
    return transactionsByUser;
  }, [transactions.data, safeUsers]);

  const allTransactions = useMemo(() => {
    if (!transactions.data) return [];
    return transactions.data.filter(t => filterType === 'all' || t.type === filterType);
  }, [transactions.data, filterType]);

  const employeePayments = useMemo(() => {
    const payments = Object.values(groupedTransactions);
    console.log('Final employee payments:', payments);
    return payments;
  }, [groupedTransactions]);

  return (
    <div className="flex flex-col">
      <Tab.Group onChange={setSelectedTab}>
        <div className="border-b border-gray-200 mb-4">
          <div className="flex justify-between items-center">
            <Tab.List className="flex space-x-4">
              <Tab className={({ selected }) => `py-2 px-4 text-sm font-medium border-b-2 ${selected ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'}`}>
                Employee Summary
              </Tab>
              <Tab className={({ selected }) => `py-2 px-4 text-sm font-medium border-b-2 ${selected ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'}`}>
                All Transactions
              </Tab>
              
            </Tab.List>
            <div className="flex items-center space-x-2">
              {selectedTab !== 2 && (
                <>
                  <span className="text-sm text-gray-500">Filter:</span>
                  <select className="text-sm border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value={filterType} onChange={(e) => setFilterType(e.target.value)}>
                    <option value="all">All Types</option>
                    <option value="salary">Salary</option>
                    <option value="wallet">Payments</option>
                    <option value="expense">Expenses</option>
                  </select>
                </>
              )}
            </div>
          </div>
        </div>

        <Tab.Panels>
          <Tab.Panel>
            <EmployeeSummaryTable 
              employeePayments={employeePayments} 
              adminEarnings={adminEarnings}
              onView={onView} 
              onEdit={onEditEmployee}
              onMakePayment={onMakePayment}
              users={safeUsers} 
            />
          </Tab.Panel>
          <Tab.Panel>
            <AllTransactionsTable 
              allTransactions={allTransactions} 
              onView={onView} 
              onEdit={onEdit} 
              onDelete={onDelete} 
            />
          </Tab.Panel>
          <Tab.Panel>
            <AdminEarningsSection adminEarnings={adminEarnings} />
          </Tab.Panel>
        </Tab.Panels>
      </Tab.Group>

      {transactions.links && (
        <div className="mt-4">
          <Pagination links={transactions.links} />
        </div>
      )}
    </div>
  );
};

export default PaymentsList;