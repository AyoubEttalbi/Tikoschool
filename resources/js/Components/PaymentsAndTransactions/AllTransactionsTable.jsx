import React from "react";
import {
    formatCurrency,
    formatDate,
    getInitials,
    getRoleBadgeColor,
    getTransactionTypeColor,
    getTransactionTypeLabel,
} from "./Utils";
// One icon library for the whole table. It used to pull ArrowPathIcon from heroicons and
// the three action icons from lucide, so two icon sets with different stroke weights and
// optical sizes sat in the same row and never lined up.
import { Eye, Pencil, Receipt, RefreshCw, Trash2 } from "lucide-react";

/** Mirrors TransactionController::EXPENSE_CATEGORIES. Free text falls through as typed. */
const EXPENSE_LABELS = {
    classroom: "Matériel de classe",
    office: "Fournitures de bureau",
    sports: "Équipement sportif",
    technology: "Technologie",
    library: "Ressources de bibliothèque",
    internet: "Internet / WiFi",
    utilities: "Eau, électricité",
    maintenance: "Maintenance",
    travel: "Sorties scolaires",
    training: "Formation du personnel",
    software: "Logiciels",
    rent: "Loyer",
    other: "Autre",
};

// The role came straight out of the database column, so a French screen showed "teacher".
const ROLE_LABELS = {
    teacher: "Enseignant",
    assistant: "Assistant",
    admin: "Administrateur",
};

const AllTransactionsTable = ({
    allTransactions,
    onView,
    onEdit,
    onDelete,
}) => {
    return (
        <div className="overflow-x-auto">
            <div className="py-2 align-middle inline-block min-w-full">
                <div className="shadow overflow-hidden border-b border-gray-200 sm:rounded-lg">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Type
                                </th>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Employé
                                </th>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Montant
                                </th>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Date
                                </th>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Description
                                </th>
                                <th
                                    scope="col"
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Statut
                                </th>
                                <th scope="col" className="relative px-6 py-3">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {allTransactions.map((transaction, index) => (
                                <tr
                                    key={transaction.id}
                                    className={`${index % 2 === 0 ? "bg-white" : "bg-gray-50"} hover:bg-gray-100 transition-colors duration-150`}
                                >
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <span
                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getTransactionTypeColor(transaction.type)}`}
                                        >
                                            {getTransactionTypeLabel(
                                                transaction.type,
                                            )}
                                        </span>
                                        {transaction.is_recurring && (
                                            <div className="mt-1 flex items-center text-xs text-gray-500">
                                                <RefreshCw className="mr-1 h-3 w-3" />
                                                Récurrent
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        {/* An expense has no payee, so it got a "?" avatar
                                            and a blank name — it read as a payment to
                                            somebody unidentified rather than as school
                                            spending. Expenses now show what they were
                                            spent on instead of who received them. */}
                                        {transaction.type === "expense" ? (
                                            <div className="flex items-center">
                                                <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-orange-100">
                                                    <Receipt className="h-4 w-4 text-orange-600" />
                                                </span>
                                                <div className="ml-3">
                                                    <div className="text-sm font-medium text-gray-900">
                                                        {EXPENSE_LABELS[
                                                            transaction.category
                                                        ] ||
                                                            transaction.category ||
                                                            "Dépense scolaire"}
                                                    </div>
                                                    <span className="text-xs text-gray-500">
                                                        Sortie d'argent
                                                    </span>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="flex items-center">
                                                <div className="flex-shrink-0 h-8 w-8">
                                                    <span className="inline-flex items-center justify-center h-8 w-8 rounded-full bg-gray-200">
                                                        <span className="text-xs font-medium leading-none text-gray-800">
                                                            {getInitials(
                                                                transaction.user
                                                                    ?.name ||
                                                                    transaction.user_name,
                                                            )}
                                                        </span>
                                                    </span>
                                                </div>
                                                <div className="ml-3">
                                                    <div className="text-sm font-medium text-gray-900">
                                                        {transaction.user
                                                            ?.name ||
                                                            transaction.user_name ||
                                                            "Personne supprimée"}
                                                    </div>
                                                    {(transaction.user?.role ||
                                                        transaction.type ===
                                                            "salary") && (
                                                        <span
                                                            className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${getRoleBadgeColor(transaction.user?.role)}`}
                                                        >
                                                            {ROLE_LABELS[
                                                                transaction.user
                                                                    ?.role
                                                            ] ||
                                                                transaction.user
                                                                    ?.role}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <div
                                            className={`text-sm font-medium ${
                                                transaction.type === "expense"
                                                    ? "text-orange-600"
                                                    : transaction.type ===
                                                        "salary"
                                                      ? "text-blue-600"
                                                      : "text-green-600"
                                            }`}
                                        >
                                            {formatCurrency(transaction.amount)}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <div className="text-sm text-gray-900">
                                            {formatDate(
                                                transaction.payment_date,
                                            )}
                                        </div>
                                        <div className="text-xs text-gray-500">
                                            {new Date(
                                                transaction.created_at,
                                            ).toLocaleDateString()}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="text-sm text-gray-900 max-w-xs truncate">
                                            {transaction.description ||
                                                "Aucune description fournie"}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        {/* This column rendered nothing at all unless the
                                            row was recurring, so "Statut" sat above a
                                            blank strip on almost every line. Every row now
                                            says what it did to a balance — the one thing
                                            about a transaction you cannot see anywhere
                                            else on this screen. */}
                                        {transaction.is_recurring &&
                                        transaction.next_payment_date ? (
                                            <div className="text-xs text-gray-500">
                                                Prochain :{" "}
                                                {formatDate(
                                                    transaction.next_payment_date,
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-xs text-gray-500">
                                                {transaction.type === "payment"
                                                    ? "Retiré du portefeuille"
                                                    : transaction.type ===
                                                        "wallet"
                                                      ? "Ajouté au portefeuille"
                                                      : transaction.type ===
                                                          "salary"
                                                        ? transaction.rest > 0
                                                            ? `Reste ${formatCurrency(transaction.rest)}`
                                                            : "Salaire réglé"
                                                        : "—"}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        {/* Bare icons with no label of any kind: a screen
                                            reader announced three unnamed buttons, and
                                            hovering gave no hint which was which. The tap
                                            target was the 20px glyph itself, which is
                                            below the 24px minimum and easy to miss on a
                                            phone — on a row whose third button deletes a
                                            payment and moves a wallet. */}
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onView(transaction.id)
                                                }
                                                title="Voir le détail"
                                                aria-label="Voir le détail de la transaction"
                                                className="rounded-md p-1.5 text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-indigo-500"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onEdit(transaction.id)
                                                }
                                                title="Modifier"
                                                aria-label="Modifier la transaction"
                                                className="rounded-md p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-400"
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onDelete(transaction.id)
                                                }
                                                title={
                                                    transaction.type ===
                                                    "payment"
                                                        ? "Supprimer — le montant retournera dans le portefeuille"
                                                        : "Supprimer"
                                                }
                                                aria-label="Supprimer la transaction"
                                                className="rounded-md p-1.5 text-red-600 transition hover:bg-red-50 hover:text-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-red-500"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
};

export default AllTransactionsTable;
