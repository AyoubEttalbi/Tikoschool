import { Head } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import AssistantPaymentsCard from "@/Components/AssistantPaymentsCard";
import { Wallet } from "lucide-react";

/*
 * « Mes paiements » — l'historique de salaire de l'assistant, à une adresse
 * prévisible depuis le menu. Même carte que sur le profil (AssistantPaymentsCard),
 * mêmes données : c'est la destination qui est nouvelle, pas le composant.
 */

export default function MyPaymentsPage({ transactions = [], userId }) {
    return (
        <>
            <Head title="Mes paiements" />

            <div className="flex-1 p-4 lg:px-8 flex flex-col gap-5">
                <header>
                    <h1 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
                        <Wallet className="w-5 h-5 text-lamaSky" aria-hidden="true" />
                        Mes paiements
                    </h1>
                    <p className="text-sm text-gray-500">
                        Vos salaires et paiements récurrents enregistrés par l'administration.
                    </p>
                </header>

                {transactions.length === 0 ? (
                    <div className="bg-white rounded-md border border-gray-100 shadow-sm p-10 text-center text-sm text-gray-400">
                        Aucun paiement enregistré pour le moment.
                    </div>
                ) : (
                    <AssistantPaymentsCard transactions={transactions} userId={userId} />
                )}
            </div>
        </>
    );
}

MyPaymentsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
