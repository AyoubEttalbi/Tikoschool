import { FaFileInvoice, FaEdit } from 'react-icons/fa';

const InvoicesTable = ({ invoices }) => {
  return (
    <div className="mb-8">
      <h2 className="text-xl font-bold mb-4 flex items-center">
        <FaFileInvoice className="mr-2" /> Invoices
      </h2>
      <table className="w-full border-collapse">
        <thead>
          <tr className="bg-gray-200">
            <th className="p-2 border">Bill Date</th>
            <th className="p-2 border">Creation Date</th>
            <th className="p-2 border">Amount</th>
            <th className="p-2 border">Rest</th>
            <th className="p-2 border">Pack</th>
            <th className="p-2 border">Actions</th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((invoice, index) => (
            <tr key={index} className="hover:bg-gray-100">
              <td className="p-2 border">{invoice.billDate}</td>
              <td className="p-2 border">{invoice.creationDate}</td>
              <td className="p-2 border">{invoice.amount}</td>
              <td className="p-2 border">{invoice.rest}</td>
              <td className="p-2 border">{invoice.pack}</td>
              <td className="p-2 border">
                <button className="text-blue-500 hover:text-blue-700">
                  <FaEdit />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default InvoicesTable;