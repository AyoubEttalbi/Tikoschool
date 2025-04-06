import React from 'react';
import TeacherInvoicesTable from './TeacherInvoicesTable';

export default function TeacherProfile({ invoices = [], paginate = [] }) {
    console.log('TeacherProfile received invoices:', invoices);
    console.log('TeacherProfile received paginate:', paginate);

    return (
        <div>
            <TeacherInvoicesTable invoices={invoices} invoiceslinks={paginate} />
        </div>
    );
}