import React, { Suspense, lazy } from "react";

// Lazy load child components
const TeacherInvoicesTable = lazy(() => import("./TeacherInvoicesTable"));
const RecurringPaymentsCard = lazy(() => import("./RecurringPaymentsCard"));

export default function TeacherProfile({
    invoices = [],
    paginate = [],
    teacher = {},
    recurringTransactions = [],
}) {
    return (
        <div className="space-y-6">
            {/* Section des paiements récurrents */}
            {recurringTransactions && recurringTransactions.length > 0 && (
                <div className="mb-6">
                    <Suspense fallback={<span>Chargement...</span>}>
                        <RecurringPaymentsCard
                            recurringTransactions={recurringTransactions}
                            userId={teacher.id}
                        />
                    </Suspense>
                </div>
            )}

            {/* Tableau des factures */}
            <Suspense fallback={<span>Chargement...</span>}>
                <TeacherInvoicesTable
                    invoices={invoices}
                    invoiceslinks={paginate}
                />
            </Suspense>
        </div>
    );
}
