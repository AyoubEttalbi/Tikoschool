import { FaFileInvoice } from 'react-icons/fa';
import FormModal from './FormModal';
import { format, parseISO } from "date-fns";
import { Printer } from 'lucide-react';
import { useState } from 'react';

const InvoicesTable = ({ invoices = [], Student_memberships = [], studentId = null }) => {
  const [loading, setLoading] = useState(false); // Global loading state

  const formatDate = (dateString, formatType) => {
    if (!dateString) return "N/A";
    try {
      const date = parseISO(dateString);
      return format(date, formatType);
    } catch (error) {
      return dateString;
    }
  };

  const handleDownload = async (invoiceId) => {
    setLoading(true);

    setTimeout(() => {
      window.location.href = `/invoices/${invoiceId}/pdf`;
      setLoading(false);
    }, 2000);
  };

  return (
    <div className="mb-8 bg-white rounded-lg shadow-md overflow-hidden relative">
      {/* Full-Screen Loading Animation */}
      {loading && (
        <div className="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50 transition-opacity duration-300">
          <div className="w-12 h-12 border-4 border-white border-t-transparent rounded-full animate-spin"></div>
        </div>
      )}

      <div className="p-4 bg-gray-200 text-black">
        <div className="flex justify-between items-center">
          <h2 className="text-xl font-bold flex items-center">
            <FaFileInvoice className="mr-2" /> Invoices
          </h2>
          <FormModal table="invoice" type="create" StudentMemberships={Student_memberships} studentId={studentId} />
        </div>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full border-collapse">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bill Date</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Creation Date</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Payment</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rest</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Offer</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {invoices.map((invoice, index) => (
              <tr key={index} className="hover:bg-gray-50 transition-colors duration-150">
                <td className="p-3 text-sm text-gray-900">{formatDate(invoice.billDate, "yyyy-MM")}</td>
                <td className="p-3 text-sm text-gray-900">{formatDate(invoice.created_at, "dd-MMM-yyyy HH:mm")}</td>
                <td className="p-3 text-sm text-gray-900">{formatDate(invoice.last_payment, "dd-MMM-yyyy HH:mm")}</td>
                <td className="p-3 text-sm text-gray-900">{invoice.amountPaid}</td>
                <td className="p-3 text-sm text-gray-900">{invoice.rest}</td>
                <td className="p-3 text-sm text-gray-900">
                  {Student_memberships.find((membership) => membership.id === invoice.membership_id)?.offer_name || '---'}
                </td>
                <td className="p-3 text-sm text-gray-900">
                  <div className="flex items-center gap-2">
                    <button
                      className="w-7 h-7 flex items-center justify-center rounded-full bg-gray-400 hover:bg-gray-500 transition duration-200"
                      onClick={() => handleDownload(invoice.id)}
                      disabled={loading}
                      aria-label="Download invoice"
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
      
      {/* Empty state */}
      {(!invoices || invoices.length === 0) && (
        <div className="p-8 text-center text-gray-500">
          No invoices found.
        </div>
      )}
    </div>
  );
};

export default InvoicesTable;