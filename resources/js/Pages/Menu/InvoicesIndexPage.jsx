import { Head, Link } from "@inertiajs/react";
import { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Pagination";
import TableSearch from "@/Components/TableSearch";
import useFilterNavigation from "@/Hooks/useFilterNavigation";
import { ReceiptText } from "lucide-react";

/*
 * La liste des factures, admin + assistant.
 *
 * Destination réelle du lien « Voir toutes les factures impayées » de l'accueil
 * assistant — qui pointait vers une route inexistante et rebondissait sur place.
 * Les filtres suivent la convention des autres pages de liste : un seul chemin de
 * navigation (useFilterNavigation), le serveur renvoie ses filtres en écho.
 */

/*
 * Statuts : la valeur du troisième onglet est 'toutes' et non 'all' — le hook de
 * navigation filtre 'all' comme valeur vide, donc un clic sur « Toutes » aurait
 * renvoyé une URL sans statut, que le contrôleur compte comme « impayées » :
 * boucle de requêtes garantie. Le contrôleur accepte les deux orthographes.
 */
const statusTabs = [
    { value: "unpaid", label: "Impayées" },
    { value: "paid", label: "Payées" },
    { value: "toutes", label: "Toutes" },
];

const dh = (value) =>
    `${new Intl.NumberFormat("fr-MA", { minimumFractionDigits: 2 }).format(value ?? 0)} DH`;

const formatDate = (dateString) =>
    dateString
        ? new Date(dateString).toLocaleDateString("fr-FR")
        : "—";

export default function InvoicesIndexPage({ invoices = [], links = [], filters }) {
    const [status, setStatus] = useState(filters?.status ?? "unpaid");
    const [search, setSearch] = useState(filters?.search ?? "");

    useFilterNavigation({
        routeName: "invoices.index",
        filters: { status, search },
        serverFilters: {
            status: filters?.status ?? "unpaid",
            search: filters?.search ?? "",
        },
    });

    const totalRest = invoices.reduce((sum, invoice) => sum + (invoice.rest || 0), 0);

    return (
        <>
            <Head title="Factures" />

            <div className="flex-1 p-4 lg:px-8 flex flex-col gap-5">
                <header className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
                            <ReceiptText className="w-5 h-5 text-lamaSky" aria-hidden="true" />
                            Factures
                        </h1>
                        <p className="text-sm text-gray-500">
                            Suivi des paiements par facture
                        </p>
                    </div>
                    <TableSearch value={search} onChange={setSearch} />
                </header>

                {/* Statuts : boutons à état enfoncé, pas un widget d'onglets —
                    il n'y a aucun panneau associé à « contrôler ». */}
                <div className="flex items-center gap-2">
                    {statusTabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            aria-pressed={status === tab.value}
                            onClick={() => setStatus(tab.value)}
                            className={`text-sm font-medium px-4 py-2 rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky ${
                                status === tab.value
                                    ? "bg-lamaSky text-white"
                                    : "bg-white border border-gray-200 text-gray-600 hover:bg-lamaSkyLight"
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Tableau */}
                <div className="bg-white rounded-md border border-gray-100 shadow-sm overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-100">
                                <th scope="col" className="px-4 py-3 font-medium">Élève</th>
                                <th scope="col" className="px-4 py-3 font-medium">Classe</th>
                                <th scope="col" className="px-4 py-3 font-medium">Offre</th>
                                <th scope="col" className="px-4 py-3 font-medium">Échéance</th>
                                <th scope="col" className="px-4 py-3 font-medium text-right">Total</th>
                                <th scope="col" className="px-4 py-3 font-medium text-right">Payé</th>
                                <th scope="col" className="px-4 py-3 font-medium text-right">Reste</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {invoices.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-10 text-center text-gray-400">
                                        Aucune facture pour ce filtre.
                                    </td>
                                </tr>
                            ) : (
                                invoices.map((invoice) => (
                                    <tr key={invoice.id} className="hover:bg-lamaSkyLight/40">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/students/${invoice.student_id}`}
                                                className="font-medium text-gray-800 hover:text-sky-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky rounded"
                                            >
                                                {invoice.student_name}
                                            </Link>
                                            {invoice.student_school && (
                                                <span className="block text-xs text-gray-400">
                                                    {invoice.student_school}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {invoice.student_class ?? "—"}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {invoice.offer_name ?? "—"}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 tabular-nums">
                                            {formatDate(invoice.billDate)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                            {dh(invoice.totalAmount)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                            {dh(invoice.amountPaid)}
                                        </td>
                                        <td
                                            className={`px-4 py-3 text-right tabular-nums font-semibold ${
                                                invoice.rest > 0 ? "text-red-600" : "text-green-600"
                                            }`}
                                        >
                                            {dh(invoice.rest)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {invoices.length > 0 && (
                    <p className="text-xs text-gray-400 -mt-2">
                        Reste à encaisser sur cette page : {dh(totalRest)}
                    </p>
                )}

                <Pagination links={links} filters={{ status, search }} />
            </div>
        </>
    );
}

InvoicesIndexPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
