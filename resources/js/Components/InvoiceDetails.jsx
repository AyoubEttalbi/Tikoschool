import React, { useState, useEffect } from "react";
import { Link } from "@inertiajs/react";
import {
    AlertCircle,
    Calendar,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    CreditCard,
    DollarSign,
    Download,
    FileText,
    User,
    X,
} from "lucide-react";

const InvoiceDetails = ({ invoice, onClose }) => {
    const [showPayments, setShowPayments] = useState(false);
    const [isDataLoaded, setIsDataLoaded] = useState(false);

    useEffect(() => {
        // Check if all necessary data is loaded
        if (invoice) {
            console.log("Invoice data loaded:", invoice);
            setIsDataLoaded(true);
        }
    }, [invoice]);

    if (!invoice) return null;

    // Calculate payment status and percentage
    const paymentStatus =
        invoice.rest <= 0
            ? "paid"
            : invoice.amountPaid <= 0
              ? "unpaid"
              : "partial";

    const totalAmount =
        typeof invoice.totalAmount === "number" ? invoice.totalAmount : 0;
    const amountPaid =
        typeof invoice.amountPaid === "number" ? invoice.amountPaid : 0;
    const remainingAmount =
        typeof invoice.rest === "number"
            ? invoice.rest
            : totalAmount - amountPaid;

    // Calculate payment percentage safely
    const paymentPercentage =
        totalAmount > 0 ? Math.round((amountPaid / totalAmount) * 100) : 0;

    // Format date helper
    const formatDate = (dateString) => {
        if (!dateString) return "N/A";
        try {
            return new Date(dateString).toLocaleDateString("en-US", {
                year: "numeric",
                month: "short",
                day: "numeric",
            });
        } catch (error) {
            console.error("Date formatting error:", error, dateString);
            return "N/A";
        }
    };

    // Format currency helper
    const formatCurrency = (amount) => {
        if (
            amount === null ||
            amount === undefined ||
            isNaN(parseFloat(amount))
        ) {
            return "0.00 DH";
        }
        return `${parseFloat(amount).toFixed(2)} DH`;
    };

    return (
        <div className="bg-white rounded-lg shadow-lg overflow-hidden max-w-3xl w-full mx-auto">
            {/* Header */}
            <div className="bg-gradient-to-r from-blue-600 to-blue-700 p-6 flex justify-between items-center">
                <div className="flex items-center">
                    <FileText className="text-white w-6 h-6 mr-3" />
                    <div>
                        <h1 className="text-xl font-bold text-white flex items-center">
                            Invoice #{invoice.id}
                            <span
                                className={`ml-3 text-xs px-2 py-1 rounded-full ${
                                    paymentStatus === "paid"
                                        ? "bg-green-500 text-white"
                                        : paymentStatus === "unpaid"
                                          ? "bg-red-500 text-white"
                                          : "bg-yellow-500 text-white"
                                }`}
                            >
                                {paymentStatus === "paid"
                                    ? "Paid"
                                    : paymentStatus === "unpaid"
                                      ? "Unpaid"
                                      : "Partially Paid"}
                            </span>
                        </h1>
                        <p className="text-blue-100 text-sm">
                            Created on {formatDate(invoice.creationDate)}
                        </p>
                    </div>
                </div>
                {onClose && (
                    <button
                        onClick={onClose}
                        className="text-white hover:bg-blue-800 rounded-full p-1"
                    >
                        <X className="w-6 h-6" />
                    </button>
                )}
            </div>

            {/* Content */}
            <div className="p-6">
                {/* Student Info */}
                <div className="mb-6 bg-gray-50 p-4 rounded-lg">
                    <h2 className="text-lg font-semibold mb-3 flex items-center">
                        <User className="w-5 h-5 mr-2 text-blue-600" />
                        Student Information
                    </h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p className="text-gray-500 text-sm">Name</p>
                            <p className="font-medium">
                                {invoice.student_name || "Unknown"}
                            </p>
                        </div>
                        <div>
                            <p className="text-gray-500 text-sm">Class</p>
                            <p className="font-medium">
                                {invoice.student_class || "N/A"}
                            </p>
                        </div>
                        <div>
                            <p className="text-gray-500 text-sm">School</p>
                            <p className="font-medium">
                                {invoice.student_school || "N/A"}
                            </p>
                        </div>
                        {invoice.student_id && (
                            <div className="flex items-end">
                                <Link
                                    href={`/students/${invoice.student_id}`}
                                    className="text-sm text-blue-600 hover:text-blue-800 flex items-center"
                                >
                                    View Student Profile
                                    <ChevronDown className="ml-1 w-4 h-4" />
                                </Link>
                            </div>
                        )}
                    </div>
                </div>

                {/* Invoice Details */}
                <div className="mb-6">
                    <h2 className="text-lg font-semibold mb-3 flex items-center">
                        <FileText className="w-5 h-5 mr-2 text-blue-600" />
                        Invoice Details
                    </h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p className="text-gray-500 text-sm">Bill Date</p>
                            <p className="font-medium">
                                {formatDate(invoice.billDate)}
                            </p>
                        </div>
                        <div>
                            <p className="text-gray-500 text-sm">End Date</p>
                            <p className="font-medium">
                                {formatDate(invoice.endDate)}
                            </p>
                        </div>
                        <div>
                            <p className="text-gray-500 text-sm">Months</p>
                            <p className="font-medium">{invoice.months || 1}</p>
                        </div>
                        <div>
                            <p className="text-gray-500 text-sm">Offer</p>
                            <p className="font-medium">
                                {invoice.offer_name || "N/A"}
                            </p>
                        </div>
                        {invoice.includePartialMonth && (
                            <div className="col-span-2">
                                <p className="text-gray-500 text-sm">
                                    Partial Month
                                </p>
                                <p className="font-medium">
                                    Yes -{" "}
                                    {formatCurrency(invoice.partialMonthAmount)}
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Payment Information */}
                <div className="mb-6">
                    <h2 className="text-lg font-semibold mb-3 flex items-center">
                        <DollarSign className="w-5 h-5 mr-2 text-blue-600" />
                        Payment Information
                    </h2>

                    <div className="bg-gray-50 p-4 rounded-lg mb-4">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <p className="text-gray-500 text-sm">
                                    Total Amount
                                </p>
                                <p className="font-medium text-lg">
                                    {formatCurrency(totalAmount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-gray-500 text-sm">
                                    Amount Paid
                                </p>
                                <p className="font-medium text-lg text-green-600">
                                    {formatCurrency(amountPaid)}
                                </p>
                            </div>
                            <div>
                                <p className="text-gray-500 text-sm">
                                    Remaining
                                </p>
                                <p className="font-medium text-lg text-red-600">
                                    {formatCurrency(remainingAmount)}
                                </p>
                            </div>
                        </div>

                        {/* Payment Progress Bar */}
                        <div className="mb-2">
                            <div className="flex justify-between text-xs text-gray-600 mb-1">
                                <span>Payment Progress</span>
                                <span>
                                    {Number.isFinite(paymentPercentage)
                                        ? paymentPercentage
                                        : 0}
                                    %
                                </span>
                            </div>
                            <div className="w-full bg-gray-200 rounded-full h-2.5">
                                <div
                                    className={`h-2.5 rounded-full ${
                                        paymentStatus === "paid"
                                            ? "bg-green-600"
                                            : paymentStatus === "partial"
                                              ? "bg-yellow-500"
                                              : "bg-red-600"
                                    }`}
                                    style={{
                                        width: `${Number.isFinite(paymentPercentage) ? paymentPercentage : 0}%`,
                                    }}
                                ></div>
                            </div>
                        </div>
                    </div>

                    {/* Payment History Toggle */}
                    {invoice.payments && invoice.payments.length > 0 && (
                        <div>
                            <button
                                onClick={() => setShowPayments(!showPayments)}
                                className="flex items-center justify-between w-full px-4 py-2 text-left text-sm font-medium text-blue-600 bg-blue-50 rounded-md hover:bg-blue-100"
                            >
                                <span>
                                    View Payment History (
                                    {invoice.payments.length})
                                </span>
                                {showPayments ? (
                                    <ChevronUp className="w-4 h-4" />
                                ) : (
                                    <ChevronDown className="w-4 h-4" />
                                )}
                            </button>

                            {showPayments && (
                                <div className="mt-3 border rounded-md overflow-hidden">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Date
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Amount
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Method
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {invoice.payments.map(
                                                (payment, index) => (
                                                    <tr
                                                        key={index}
                                                        className="hover:bg-gray-50"
                                                    >
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                                            {formatDate(
                                                                payment.date,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-green-600">
                                                            {formatCurrency(
                                                                payment.amount,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                                            {payment.method ||
                                                                "N/A"}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Teacher Information */}
                {invoice.teachers && invoice.teachers.length > 0 && (
                    <div className="mb-6">
                        <h2 className="text-lg font-semibold mb-3 flex items-center">
                            <User className="w-5 h-5 mr-2 text-blue-600" />
                            Teacher Information
                        </h2>
                        <div className="border rounded-md overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Name
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {invoice.teachers.map((teacher, index) => (
                                        <tr
                                            key={index}
                                            className="hover:bg-gray-50"
                                        >
                                            <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                                {teacher.name ||
                                                    `Teacher #${teacher.teacherId}`}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-600">
                                                {formatCurrency(
                                                    teacher.amount *
                                                        (invoice.months || 1),
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            {/* Footer */}
            <div className="border-t border-gray-200 p-4 bg-gray-50 flex justify-between items-center">
                <div className="text-gray-500 text-sm">
                    <span className="mr-2">
                        <Calendar className="w-4 h-4 inline mr-1" />
                        Bill: {formatDate(invoice.billDate)}
                    </span>
                    <span>
                        <Calendar className="w-4 h-4 inline mr-1" />
                        End: {formatDate(invoice.endDate)}
                    </span>
                </div>
                <div>
                    <button
                        onClick={() =>
                            window.open(
                                `/invoices/${invoice.id}/download`,
                                "_blank",
                            )
                        }
                        className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 flex items-center"
                    >
                        <Download className="w-4 h-4 mr-1" />
                        Download Invoice
                    </button>
                </div>
            </div>
            {!isDataLoaded && (
                <div className="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center">
                    <div className="text-gray-500">Loading invoice data...</div>
                </div>
            )}
        </div>
    );
};

export default InvoiceDetails;
