import React, { useState, useEffect } from "react";
import {
    CreditCard,
    Calendar,
    Clock,
    AlertCircle,
    CheckCircle,
} from "lucide-react";
import { format } from "date-fns";

const RecurringPaymentsCard = ({ recurringTransactions = [], userId }) => {
    const [isLoading, setIsLoading] = useState(true);
    const [filteredTransactions, setFilteredTransactions] = useState([]);

    useEffect(() => {
        if (recurringTransactions) {
            const userTransactions = recurringTransactions.filter(
                (transaction) => transaction.user_id === userId,
            );
            setFilteredTransactions(userTransactions);
            setIsLoading(false);
        }
    }, [recurringTransactions, userId]);

    const formatDate = (dateString) => {
        if (!dateString) return "Non planifié";
        try {
            return format(new Date(dateString), "dd MMM yyyy");
        } catch (e) {
            return "Date invalide";
        }
    };

    const getPaymentStatusIcon = (transaction) => {
        const currentDate = new Date();
        const currentMonth = currentDate.getMonth();
        const currentYear = currentDate.getFullYear();
        if (transaction.paid_this_month) {
            return (
                <div className="flex items-center text-green-600">
                    <CheckCircle className="h-4 w-4 mr-1" />
                    <span>Payé ce mois-ci</span>
                </div>
            );
        }
        if (transaction.payment_date) {
            const paymentDate = new Date(transaction.payment_date);
            if (
                paymentDate.getMonth() === currentMonth &&
                paymentDate.getFullYear() === currentYear
            ) {
                return (
                    <div className="flex items-center text-green-600">
                        <CheckCircle className="h-4 w-4 mr-1" />
                        <span>
                            Payé le {formatDate(transaction.payment_date)}
                        </span>
                    </div>
                );
            }
        }
        if (transaction.next_payment_date) {
            const nextPaymentDate = new Date(transaction.next_payment_date);
            if (nextPaymentDate < currentDate) {
                return (
                    <div className="flex items-center text-red-600">
                        <AlertCircle className="h-4 w-4 mr-1" />
                        <span>
                            En retard depuis {formatDate(transaction.next_payment_date)}
                        </span>
                    </div>
                );
            }
            const diffTime = Math.abs(nextPaymentDate - currentDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays <= 7) {
                return (
                    <div className="flex items-center text-amber-600">
                        <Clock className="h-4 w-4 mr-1" />
                        <span>
                            Échéance proche ({formatDate(transaction.next_payment_date)})
                        </span>
                    </div>
                );
            }
            return (
                <div className="flex items-center text-blue-600">
                    <Calendar className="h-4 w-4 mr-1" />
                    <span>
                        Prévu pour {formatDate(transaction.next_payment_date)}
                    </span>
                </div>
            );
        }
        return (
            <div className="flex items-center text-gray-600">
                <Calendar className="h-4 w-4 mr-1" />
                <span>Non planifié</span>
            </div>
        );
    };

    if (isLoading) {
        return (
            <div className="bg-white shadow rounded-lg p-4 animate-pulse">
                <div className="h-5 bg-gray-200 rounded w-1/3 mb-4"></div>
                <div className="h-4 bg-gray-200 rounded w-full mb-2"></div>
                <div className="h-4 bg-gray-200 rounded w-full mb-2"></div>
                <div className="h-4 bg-gray-200 rounded w-2/3"></div>
            </div>
        );
    }

    if (filteredTransactions.length === 0) {
        return null;
    }

    return (
        <div className="bg-white shadow rounded-lg overflow-hidden">
            <div className="bg-blue-50 px-4 py-3 border-b border-blue-100">
                <div className="flex items-center">
                    <CreditCard className="h-5 w-5 text-blue-600 mr-2" />
                    <h3 className="text-lg font-medium text-blue-800">
                        Paiements récurrents
                    </h3>
                </div>
            </div>
            <div className="divide-y divide-gray-200">
                {filteredTransactions.map((transaction) => (
                    <div
                        key={transaction.id}
                        className="p-4 hover:bg-gray-50 transition-colors"
                    >
                        <div className="flex justify-between items-center mb-1">
                            <h4 className="font-medium text-gray-900">
                                {transaction.type === "salary"
                                    ? "Paiement de salaire"
                                    : transaction.type}
                            </h4>
                            <div className="text-sm font-medium text-gray-900">
                                {parseFloat(transaction.amount).toFixed(2)} DH
                            </div>
                        </div>
                        <div className="text-sm text-gray-500 mb-1">
                            {transaction.description || "Aucune description"}
                        </div>
                        <div className="flex items-center justify-between">
                            <div className="text-xs text-gray-500">
                                Fréquence : {transaction.frequency.charAt(0).toUpperCase() + transaction.frequency.slice(1)}
                            </div>
                            <div className="text-xs">
                                {getPaymentStatusIcon(transaction)}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default RecurringPaymentsCard;
