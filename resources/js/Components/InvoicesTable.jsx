import { FaFileInvoice } from 'react-icons/fa';
import FormModal from './FormModal';
import { format, parseISO } from "date-fns";
import { Printer, AlertCircle } from 'lucide-react';
import { useState } from 'react';

const InvoicesTable = ({ invoices = [], Student_memberships = [], studentId = null }) => {
  const [loading, setLoading] = useState(false); // Global loading state
  
  // Count unpaid memberships
  const unpaidMembershipsCount = Student_memberships.filter(m => m.payment_status !== "paid").length;

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
          <div className="flex items-center gap-2">
            <FormModal table="invoice" type="create" StudentMemberships={Student_memberships} studentId={studentId} />
            {unpaidMembershipsCount > 0 && (
              <span className="bg-amber-100 text-amber-800 text-xs font-medium px-2 py-0.5 rounded-full">
                {unpaidMembershipsCount} unpaid {unpaidMembershipsCount === 1 ? 'membership' : 'memberships'}
              </span>
            )}
          </div>
        </div>
      </div>
      
      {/* Unpaid memberships notification */}
      {unpaidMembershipsCount > 0 && (
        <div className="p-3 bg-amber-50 border-b border-amber-200">
          <div className="flex items-start">
            <AlertCircle className="h-5 w-5 text-amber-500 mt-0.5 mr-2 flex-shrink-0" />
            <div>
              <p className="text-sm text-amber-700">
                This student has {unpaidMembershipsCount} unpaid {unpaidMembershipsCount === 1 ? 'membership' : 'memberships'}.
                Create a new invoice to complete the payment.
              </p>
            </div>
          </div>
        </div>
      )}

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
            {invoices.map((invoice, index) => {
              // Find associated membership
              const membership = Student_memberships.find((m) => m.id === invoice.membership_id);
              const isPaid = membership && membership.payment_status === "paid";
              
              return (
                <tr key={index} className={`hover:bg-gray-50 transition-colors duration-150 ${!isPaid ? 'bg-amber-50' : ''}`}>
                  <td className="p-3 text-sm text-gray-900">{formatDate(invoice.billDate, "yyyy-MM")}</td>
                  <td className="p-3 text-sm text-gray-900">{formatDate(invoice.created_at, "dd-MMM-yyyy HH:mm")}</td>
                  <td className="p-3 text-sm text-gray-900">{formatDate(invoice.last_payment, "dd-MMM-yyyy HH:mm")}</td>
                  <td className="p-3 text-sm text-gray-900">{invoice.amountPaid} DH</td>
                  <td className="p-3 text-sm text-gray-900">
                    {invoice.rest > 0 ? (
                      <span className="text-amber-700 font-medium">{invoice.rest} DH</span>
                    ) : (
                      <span className="text-green-700 font-medium">0 DH</span>
                    )}
                  </td>
                  <td className="p-3 text-sm text-gray-900">
                    <div className="flex items-center">
                      {Student_memberships.find((membership) => membership.id === invoice.membership_id)?.offer_name || '---'}
                      {!isPaid && (
                        <span className="ml-2 bg-amber-100 text-amber-800 text-xs px-2 py-0.5 rounded-full">Unpaid</span>
                      )}
                    </div>
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
              );
            })}
          </tbody>
        </table>
      </div>
      
      {/* Empty state */}
      {(!invoices || invoices.length === 0) && (
        <div className="p-8 text-center text-gray-500">
          {unpaidMembershipsCount > 0 ? (
            <div className="flex flex-col items-center">
              <p className="mb-4">No invoices found. Create an invoice to complete membership payment.</p>
              <FormModal table="invoice" type="create" StudentMemberships={Student_memberships} studentId={studentId} />
            </div>
          ) : (
            <p>No invoices found.</p>
          )}
        </div>
      )}
    </div>
  );
};

export default InvoicesTable;