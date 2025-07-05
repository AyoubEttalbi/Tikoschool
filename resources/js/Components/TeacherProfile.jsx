import React, { Suspense, lazy } from "react";

// Lazy load child components
const TeacherInvoicesTable = lazy(() => import("./TeacherInvoicesTable"));
const RecurringPaymentsCard = lazy(() => import("./RecurringPaymentsCard"));

export default function TeacherProfile({
    invoices = [],
    paginate = [],
    teacher = {},
    transactions = [],
}) {
    return (
        <div className="space-y-6">
            

            {/* Tableau des factures */}
            <Suspense fallback={<span>Chargement...</span>}>
                <TeacherInvoicesTable
                    invoices={invoices}
                    invoiceslinks={paginate}
                />
            </Suspense>
            {/* Section des paiements */}
            {transactions && transactions.length > 0 && (
                <div className="mb-6">
                    <Suspense fallback={<span>Chargement...</span>}>
                        <RecurringPaymentsCard
                            transactions={transactions}
                            userId={teacher.id}
                        />
                    </Suspense>
                </div>
            )}
        </div>
    );
}
