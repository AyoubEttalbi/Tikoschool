import React from 'react'
import TeacherInvoicesTable from './TeacherInvoicesTable'


export default function TeacherProfile({invoices}) {
    console.log(invoices)
  return (
    <div>
      <TeacherInvoicesTable invoices={invoices} />
    </div>
  )
}
