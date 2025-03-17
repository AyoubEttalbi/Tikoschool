import React from 'react';
import TeacherInvoicesTable from './TeacherInvoicesTable';

export default function TeacherProfile({ invoices = [], paginate = [] }) {
    console.log('Invoices:', invoices);
    console.log('Paginate:', paginate);

    return (
        <div>
            <TeacherInvoicesTable invoices={invoices} invoiceslinks={paginate} />
        </div>
    );
}