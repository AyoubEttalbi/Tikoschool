import React, { Suspense, lazy } from "react";

const AssistantPaymentsCard = lazy(() => import("./AssistantPaymentsCard"));

export default function AssistantProfile({
    assistant = {},
    transactions = [],
}) {
    console.log('transactions', transactions);
    console.log('assistant.user_id', assistant.user_id);
    return (
        <div className="space-y-6">
            {/* Section des paiements */}
            {transactions && transactions.length > 0 && (
                <div className="mb-6">
                    <Suspense fallback={<span>Chargement...</span>}>
                        <AssistantPaymentsCard
                            transactions={transactions}
                            userId={assistant.user_id}
                        />
                    </Suspense>
                </div>
            )}
        </div>
    );
}
