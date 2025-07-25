import React from 'react';
import TeacherInvoicesTable from './TeacherInvoicesTable';
import RecurringPaymentsCard from './RecurringPaymentsCard';

export default function TeacherProfile({ invoices = [], paginate = [], teacher = {}, recurringTransactions = [] }) {
    console.log('TeacherProfile received invoices:', invoices);
    console.log('TeacherProfile received paginate:', paginate);

    return (
        <div className="space-y-6">
            {/* Recurring Payments Section */}
            {recurringTransactions && recurringTransactions.length > 0 && (
                <div className="mb-6">
                    <RecurringPaymentsCard 
                        recurringTransactions={recurringTransactions} 
                        userId={teacher.id}
                    />
                </div>
            )}
            
            {/* Invoices Table */}
            <TeacherInvoicesTable invoices={invoices} invoiceslinks={paginate} />
        </div>
    );
}