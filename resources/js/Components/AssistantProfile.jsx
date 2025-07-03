import React, { Suspense, lazy } from "react";

const RecurringPaymentsCard = lazy(() => import("./RecurringPaymentsCard"));

export default function AssistantProfile({
    assistant = {},
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
                            userId={assistant.id}
                        />
                    </Suspense>
                </div>
            )}
        </div>
    );
}
