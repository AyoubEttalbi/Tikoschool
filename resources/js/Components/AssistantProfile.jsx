import React from 'react';
import RecurringPaymentsCard from './RecurringPaymentsCard';

export default function AssistantProfile({ assistant = {}, recurringTransactions = [] }) {
    return (
        <div className="space-y-6">
            {/* Recurring Payments Section */}
            {recurringTransactions && recurringTransactions.length > 0 && (
                <div className="mb-6">
                    <RecurringPaymentsCard 
                        recurringTransactions={recurringTransactions} 
                        userId={assistant.id}
                    />
                </div>
            )}
        </div>
    );
} 