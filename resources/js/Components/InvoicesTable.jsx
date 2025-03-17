import { FaFileInvoice } from 'react-icons/fa';
import FormModal from './FormModal';
import { format, parseISO } from "date-fns";
import { Printer } from 'lucide-react';
import { useState } from 'react';

const InvoicesTable = ({ invoices = [], Student_memberships = [], studentId = null }) => {
  const [loading, setLoading] = useState(false); // Global loading state

  const formatDate = (dateString, formatType) => {
    if (!dateString) return "N/A";
    const date = parseISO(dateString);
    return format(date, formatType);
  };

  const handleDownload = async (invoiceId) => {
    setLoading(true);

    setTimeout(() => {
      window.location.href = `/invoices/${invoiceId}/pdf`;
      setLoading(false);
    }, 2000);
  };

  return (
    <div className="mb-8 relative">
      {/* Full-Screen Loading Animation */}
      {loading && (
        <div className="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50 transition-opacity duration-300">
          <div className="w-12 h-12 border-4 border-white border-t-transparent rounded-full animate-spin"></div>
        </div>
      )}

      <div className="flex justify-between items-center">
        <h2 className="text-xl font-bold mb-4 flex items-center">
          <FaFileInvoice className="mr-2" /> Invoices
        </h2>
        <FormModal table="invoice" type="create" StudentMemberships={Student_memberships} studentId={studentId} />
      </div>

      <table className="w-full border-collapse">
        <thead>
          <tr className="bg-gray-200">
            <th className="p-2 border text-left">Bill Date</th>
            <th className="p-2 border text-left">Creation Date</th>
            <th className="p-2 border text-left">Last Payment</th>
            <th className="p-2 border text-left">Amount</th>
            <th className="p-2 border text-left">Rest</th>
            <th className="p-2 border text-left">Offer</th>
            <th className="p-2 border text-left">Actions</th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((invoice, index) => (
            <tr key={index} className="hover:bg-gray-100 transition-all duration-200">
              <td className="p-2 border">{formatDate(invoice.billDate, "yyyy-MM")}</td>
              <td className="p-2 border">{formatDate(invoice.created_at, "dd-MMM-yyyy HH:mm")}</td>
              <td className="p-2 border">{formatDate(invoice.last_payment, "dd-MMM-yyyy HH:mm")}</td>
              <td className="p-2 border">{invoice.amountPaid}</td>
              <td className="p-2 border">{invoice.rest}</td>
              <td className="p-2 border">
                {Student_memberships.find((membership) => membership.id === invoice.membership_id)?.offer_name}
              </td>
              <td className="p-2 border">
                <div className="flex items-center gap-2">
                  <button
                    className="w-7 h-7 flex items-center justify-center rounded-full bg-gray-400 hover:bg-gray-500 transition duration-200"
                    onClick={() => handleDownload(invoice.id)}
                    disabled={loading}
                  >
                    <Printer className="w-4 h-4 text-white" />
                  </button>
                  <FormModal table="invoice" type="update" id={invoice.id} studentId={studentId} data={invoice} StudentMemberships={Student_memberships} />
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
