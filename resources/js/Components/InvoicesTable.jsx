import { FaFileInvoice, FaEdit } from 'react-icons/fa';
import FormModal from './FormModal';
import { format, parseISO } from "date-fns";
import { Printer } from 'lucide-react';
const InvoicesTable = ({ invoices, Student_memberships }) => {
  const formatDate = (dateString, formatType) => {
    if (!dateString) return "N/A"; // Handle empty values

    const date = parseISO(dateString);
    return format(date, formatType);
  };
  console.log('memberships tablessss', Student_memberships)
  return (
    <div className="mb-8">
      <div className="flex justify-between">

        <h2 className="text-xl font-bold mb-4 flex items-center"><FaFileInvoice className="mr-2" /> Invoices
        </h2>
         <FormModal table="invoice" type="create" StudentMemberships={Student_memberships} /> 
      </div>
      <table className="w-full border-collapse">
        <thead>
          <tr className="bg-gray-200">
            <th className="p-2 border">Bill Date</th>
            <th className="p-2 border">Creation Date</th>
            <th className="p-2 border">Amount</th>
            <th className="p-2 border">Rest</th>
            <th className="p-2 border">Offer</th>
            <th className="p-2 border">Actions</th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((invoice, index) => (
            <tr key={index} className="hover:bg-gray-100">
              <td className="p-2 border">{formatDate(invoice.billDate, "yyyy-MM")}</td>
              <td className="p-2 border">{formatDate(invoice.creationDate, "dd-MMM-yyyy HH:mm")}</td>
              <td className="p-2 border">{invoice.amountPaid}</td>
              <td className="p-2 border">{invoice.rest}</td>
              <td className="p-2 border">{Student_memberships.find((membership) => membership.id === invoice.membership_id)?.offer_name}</td>
              <td className="p-2 border">
                <div className="flex flex-row justify-around">
                  <button className="">

                    <Printer className='text-white  bg-gray-400 rounded-full p-1 w-7  h-7' />
                  </button>
                  <FormModal table="invoice" type="update" id={invoice.id} data={invoice} StudentMemberships={Student_memberships} />
                  <FormModal table="invoice" type="delete" id={invoice.id} route="invoices" />

                </div>

              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default InvoicesTable;