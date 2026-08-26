import { useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import AssistantPaymentsCard from "@/Components/AssistantPaymentsCard";
import { ArrowDownLeft, ArrowUpRight, Coins, Wallet } from "lucide-react";

/*
 * « Mes paiements » — la paie des deux rôles staff, à une adresse prévisible.
 *
 * Assistant : historique de salaire (carte existante, mêmes données).
 * Enseignant : le PORTEFEUILLE de commissions — solde, ligne "si l'administration
 * payait aujourd'hui", et le registre complet (teacher_wallet_entries) libellé,
 * avec le solde courant après chaque mouvement. Le registre est append-only :
 * ce qui est affiché ne peut pas avoir été réécrit après coup.
 */

const dh = (value) =>
    `${new Intl.NumberFormat("fr-MA", { minimumFractionDigits: 2 }).format(value ?? 0)} DH`;

const formatDate = (dateString) =>
    dateString
        ? new Date(dateString).toLocaleDateString("fr-FR", {
              day: "numeric",
              month: "short",
              year: "numeric",
          })
        : "—";

function WalletSummary({ wallet }) {
    const balance = Number(wallet.balance ?? 0);
    const pending = Number(wallet.estimatedPending ?? 0);

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Le solde : LA réponse à "où j'en suis". */}
            <div className="bg-lamaPurple rounded-2xl p-5">
                <div className="flex items-center justify-between">
                    <span className="h-9 w-9 rounded-full flex items-center justify-center bg-white/70 text-purple-700">
                        <Wallet className="w-5 h-5" aria-hidden="true" />
                    </span>
                </div>
                <p className="mt-3 text-xs font-medium uppercase tracking-wide text-gray-700">
                    Solde de mon portefeuille
                </p>
                <p className="mt-1 text-3xl font-bold text-gray-900 tabular-nums leading-tight">
                    {dh(balance)}
                </p>
            </div>

            {/* L'attente : ce qui est gagné mais pas encore versé. */}
            <div className="bg-lamaYellowLight border border-lamaYellow rounded-2xl p-5 flex flex-col gap-2">
                <span className="h-9 w-9 rounded-full flex items-center justify-center bg-lamaYellow text-amber-700">
                    <Coins className="w-5 h-5" aria-hidden="true" />
                </span>
                <p className="text-xs font-medium uppercase tracking-wide text-gray-600 mt-1">
                    Commissions en attente de versement
                </p>
                <p className="text-2xl font-bold text-gray-900 tabular-nums">{dh(pending)}</p>
                <p className="text-xs text-gray-500">
                    {pending > 0
                        ? "Gagné sur des factures encaissées, versement à venir."
                        : "Rien en attente — tout est réglé."}
                </p>
            </div>
        </div>
    );
}

function LedgerTable({ ledger }) {
    if (!ledger || ledger.length === 0) {
        return (
            <div className="bg-white rounded-md border border-gray-100 shadow-sm p-10 text-center text-sm text-gray-400">
                Aucun mouvement sur votre portefeuille pour le moment.
            </div>
        );
    }

    return (
        <div className="bg-white rounded-md border border-gray-100 shadow-sm overflow-x-auto">
            <table className="min-w-full text-sm">
                <thead>
                    <tr className="border-b border-gray-100 bg-gray-50/60">
                        <th className="px-4 py-2.5 text-left font-medium text-gray-500">Date</th>
                        <th className="px-4 py-2.5 text-left font-medium text-gray-500">Opération</th>
                        <th className="px-4 py-2.5 text-left font-medium text-gray-500 hidden sm:table-cell">Mois</th>
                        <th className="px-4 py-2.5 text-right font-medium text-gray-500">Montant</th>
                        <th className="px-4 py-2.5 text-right font-medium text-gray-500">Solde après</th>
                    </tr>
                </thead>
                <tbody>
                    {ledger.map((entry) => {
                        const credit = entry.amount >= 0;
                        const Icon = credit ? ArrowDownLeft : ArrowUpRight;

                        return (
                            <tr key={entry.id} className="border-b border-gray-50 last:border-b-0 hover:bg-gray-50/60 transition-colors">
                                <td className="px-4 py-2.5 text-gray-500 whitespace-nowrap">
                                    {formatDate(entry.date)}
                                </td>
                                <td className="px-4 py-2.5">
                                    <span className="flex items-center gap-2">
                                        <Icon
                                            className={`w-4 h-4 shrink-0 ${credit ? "text-green-500" : "text-red-400"}`}
                                            aria-hidden="true"
                                        />
                                        <span className="text-gray-800 font-medium">{entry.label}</span>
                                        {entry.note && (
                                            <span className="text-xs text-gray-400 truncate max-w-[14rem] hidden md:inline">
                                                — {entry.note}
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-gray-500 tabular-nums hidden sm:table-cell">
                                    {entry.month ?? "—"}
                                </td>
                                <td
                                    className={`px-4 py-2.5 text-right font-semibold tabular-nums ${
                                        credit ? "text-green-600" : "text-red-500"
                                    }`}
                                >
                                    {credit ? "+" : "−"}
                                    {dh(Math.abs(entry.amount))}
                                </td>
                                <td className="px-4 py-2.5 text-right text-gray-600 tabular-nums">
                                    {dh(entry.balance_after)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/* Pagination commune — prev/next + compteur, sans dépendance externe. */
function PageNav({ page, onPage }) {
    if (!page || page.last_page <= 1) return null;

    return (
        <nav className="flex items-center justify-between pt-3" aria-label="Pagination">
            <span className="text-xs text-gray-400 tabular-nums">
                Page {page.current_page} sur {page.last_page} — {page.total} ligne{page.total > 1 ? "s" : ""}
            </span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={page.current_page <= 1}
                    onClick={() => onPage(page.current_page - 1)}
                    className="px-3 py-1.5 rounded-md border border-gray-200 bg-white text-xs font-medium text-gray-600 hover:bg-lamaSkyLight disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                >
                    Précédent
                </button>
                <button
                    type="button"
                    disabled={page.current_page >= page.last_page}
                    onClick={() => onPage(page.current_page + 1)}
                    className="px-3 py-1.5 rounded-md border border-gray-200 bg-white text-xs font-medium text-gray-600 hover:bg-lamaSkyLight disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                >
                    Suivant
                </button>
            </div>
        </nav>
    );
}

/* Cartes résumé — mêmes repères que la table « Gains Mensuels » de la fiche. */
function GainsStats({ stats }) {
    const best = stats?.best_offer ?? null;

    return (
        <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
            <div className="bg-lamaSky rounded-2xl p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-700">Gains totaux</p>
                <p className="mt-1 text-2xl font-bold text-gray-900 tabular-nums">{dh(stats?.total_gains)}</p>
                <p className="mt-0.5 text-xs text-gray-700">
                    sur {stats?.unique_students ?? 0} élève{(stats?.unique_students ?? 0) > 1 ? "s" : ""}
                </p>
            </div>
            <div className="bg-green-200 rounded-2xl p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-700">Ce mois-ci</p>
                <p className="mt-1 text-2xl font-bold text-gray-900 tabular-nums">{dh(stats?.current_month_gains)}</p>
            </div>
            <div className="bg-lamaPurple rounded-2xl p-4 min-w-0">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-700">Meilleure offre</p>
                <p className="mt-1 truncate font-semibold text-gray-900">{best ? best.name : "—"}</p>
                <p className="text-sm tabular-nums text-gray-800">{best ? dh(best.amount) : ""}</p>
            </div>
            <div className="bg-lamaYellowLight border border-lamaYellow rounded-2xl p-4">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-600">Mois en attente</p>
                <p className="mt-1 text-2xl font-bold text-gray-900 tabular-nums">{stats?.pending_months ?? 0}</p>
                <p className="mt-0.5 text-xs text-gray-500">pas encore versés</p>
            </div>
        </div>
    );
}

/* Total par mois — lecture rapide de la tendance sans table. */
function MonthTotals({ months }) {
    if (!months || months.length === 0) return null;

    return (
        <div className="flex gap-2 overflow-x-auto pb-1" aria-label="Total des gains par mois">
            {months.map((m) => (
                <div
                    key={m.month}
                    className="shrink-0 rounded-xl bg-white border border-gray-100 shadow-sm px-3 py-2 min-w-[6.5rem]"
                >
                    <p className="text-[11px] font-medium uppercase tracking-wide text-gray-400">{m.label}</p>
                    <p className="text-sm font-bold text-gray-900 tabular-nums">{dh(m.total)}</p>
                </div>
            ))}
        </div>
    );
}

const selectClass =
    "rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky";

/* Barre de filtres — la même logique que « Gains Mensuels » : recherche élève,
   classe, offre, école, mois, Payé/En attente. Chaque changement relance le
   serveur et remet la pagination à zéro. */
function GainsFilters({ filters, options, onFilter }) {
    const [search, setSearch] = useState(filters?.search ?? "");
    const committed = useRef(filters?.search ?? "");

    const apply = (patch) => onFilter(patch);
    const commitSearch = () => {
        if (search !== committed.current) {
            committed.current = search;
            apply({ search });
        }
    };

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                commitSearch();
            }}
        >
            <input
                type="search"
                value={search}
                placeholder="Rechercher un élève..."
                onChange={(e) => setSearch(e.target.value)}
                onBlur={commitSearch}
                aria-label="Rechercher un élève"
                className="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 w-48 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
            />
            <select
                value={filters?.class_filter ?? "all"}
                onChange={(e) => apply({ class_filter: e.target.value })}
                className={selectClass}
                aria-label="Filtrer par classe"
            >
                <option value="all">Toutes les classes</option>
                {(options?.classes ?? []).map((c) => (
                    <option key={c} value={c}>{c}</option>
                ))}
            </select>
            <select
                value={filters?.offer_filter ?? "all"}
                onChange={(e) => apply({ offer_filter: e.target.value })}
                className={selectClass}
                aria-label="Filtrer par offre"
            >
                <option value="all">Toutes les offres</option>
                {(options?.offers ?? []).map((o) => (
                    <option key={o} value={o}>{o}</option>
                ))}
            </select>
            <select
                value={filters?.school_filter ?? "all"}
                onChange={(e) => apply({ school_filter: e.target.value })}
                className={selectClass}
                aria-label="Filtrer par école"
            >
                <option value="all">Toutes les écoles</option>
                {(options?.schools ?? []).map((s) => (
                    <option key={s} value={s}>{s}</option>
                ))}
            </select>
            <select
                value={filters?.date_filter ?? "all"}
                onChange={(e) => apply({ date_filter: e.target.value })}
                className={selectClass}
                aria-label="Filtrer par mois"
            >
                <option value="all">Tous les mois</option>
                {(options?.months ?? []).map((m) => (
                    <option key={m} value={m}>
                        {new Date(m + "-01").toLocaleDateString("fr-FR", { month: "long", year: "numeric" })}
                    </option>
                ))}
            </select>
            <select
                value={filters?.payment_status_filter ?? "all"}
                onChange={(e) => apply({ payment_status_filter: e.target.value })}
                className={selectClass}
                aria-label="Filtrer par statut"
            >
                <option value="all">Tous les statuts</option>
                <option value="paid">Payé</option>
                <option value="pending">En attente</option>
            </select>
            {(filters?.search || Object.values(filters ?? {}).some((v) => v !== "all")) && (
                <button
                    type="button"
                    onClick={() => {
                        setSearch("");
                        committed.current = "";
                        apply({
                            search: "", class_filter: "all", offer_filter: "all",
                            school_filter: "all", date_filter: "all", payment_status_filter: "all",
                        });
                    }}
                    className="text-xs font-semibold text-sky-700 hover:text-sky-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky rounded"
                >
                    Réinitialiser
                </button>
            )}
        </form>
    );
}

/* « Mes gains » : une ligne par mois de facture, même mécanique que la table
   « Gains Mensuels » de la fiche enseignant (part = % offre × montant payé). */
function GainsSection({ gains, gainsFilters, gainsOptions, gainsStats, gainsMonths }) {
    const rows = gains?.data ?? [];

    // One entry point for every filter/page change: server-side filtering and
    // pagination, so the URL stays shareable and nothing is truncated client-side.
    const applyQuery = (patch) => {
        router.get(
            route("staff.my-payments"),
            { ...gainsFilters, ...patch },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-sm font-semibold text-gray-700">
                Mes gains — par facture et par mois
            </h2>

            <GainsStats stats={gainsStats} />
            <MonthTotals months={gainsMonths} />

            <GainsFilters
                filters={gainsFilters}
                options={gainsOptions}
                onFilter={(patch) => applyQuery({ ...patch, gains_page: 1 })}
            />

            {rows.length === 0 ? (
                <div className="bg-white rounded-md border border-gray-100 shadow-sm p-10 text-center text-sm text-gray-400">
                    Aucun gain pour ces filtres — ajustez la recherche ou la période.
                </div>
            ) : (
                <div className="bg-white rounded-md border border-gray-100 shadow-sm px-4 py-3">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100">
                                    <th className="px-3 py-2 text-left font-medium text-gray-500">Mois</th>
                                    <th className="px-3 py-2 text-left font-medium text-gray-500">Élève</th>
                                    <th className="px-3 py-2 text-left font-medium text-gray-500 hidden md:table-cell">Classe</th>
                                    <th className="px-3 py-2 text-left font-medium text-gray-500 hidden lg:table-cell">Offre</th>
                                    <th className="px-3 py-2 text-right font-medium text-gray-500 hidden lg:table-cell">Facture payée</th>
                                    <th className="px-3 py-2 text-right font-medium text-gray-500">Ma part</th>
                                    <th className="px-3 py-2 text-center font-medium text-gray-500">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id} className="border-b border-gray-50 last:border-b-0 hover:bg-gray-50/60 transition-colors">
                                        <td className="px-3 py-2.5 text-gray-700 whitespace-nowrap">{row.month_display}</td>
                                        <td className="px-3 py-2.5 text-gray-800 font-medium">{row.student_name}</td>
                                        <td className="px-3 py-2.5 text-gray-600 hidden md:table-cell">{row.student_class}</td>
                                        <td className="px-3 py-2.5 text-gray-600 hidden lg:table-cell">{row.offer_name ?? "—"}</td>
                                        <td className="px-3 py-2.5 text-right tabular-nums hidden lg:table-cell">
                                            {dh(row.amountPaid)} / {dh(row.totalAmount)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right font-semibold tabular-nums text-sky-800">
                                            {dh(row.teacher_amount)}
                                        </td>
                                        <td className="px-3 py-2.5 text-center">
                                            <span
                                                className={`inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold ${
                                                    row.is_month_paid
                                                        ? "bg-green-50 text-green-700"
                                                        : "bg-amber-50 text-amber-700"
                                                }`}
                                            >
                                                {row.is_month_paid ? "Payé" : "En attente"}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <PageNav page={gains} onPage={(value) => applyQuery({ gains_page: value })} />
                </div>
            )}
        </section>
    );
}

export default function MyPaymentsPage({
    transactions = [],
    userId,
    wallet = null,
    ledger = [],
    gains = null,
    gainsFilters = {},
    gainsOptions = {},
    gainsStats = {},
    gainsMonths = [],
}) {
    const isTeacher = wallet?.roleView === "teacher";

    // Pagination navigates with query params so the server re-renders the page:
    // the ledger and gains lists are server-paginated, never client-truncated.
    const goToPage = (param, value) => {
        router.get(
            route("staff.my-payments"),
            { [param]: value },
            { preserveState: true, preserveScroll: true },
        );
    };

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
                        {isTeacher
                            ? "Votre portefeuille de commissions, vos gains par facture et vos versements."
                            : "Vos salaires et paiements récurrents enregistrés par l'administration."}
                    </p>
                </header>

                {isTeacher && (
                    <>
                        <WalletSummary wallet={wallet} />

                        <GainsSection
                            gains={gains}
                            gainsFilters={gainsFilters}
                            gainsOptions={gainsOptions}
                            gainsStats={gainsStats}
                            gainsMonths={gainsMonths}
                        />

                        <section className="flex flex-col gap-3">
                            <h2 className="text-sm font-semibold text-gray-700">
                                Registre du portefeuille
                            </h2>
                            <LedgerTable ledger={ledger?.data ?? []} />
                            <PageNav
                                page={ledger}
                                onPage={(value) => goToPage("ledger_page", value)}
                            />
                        </section>
                    </>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold text-gray-700">
                        {isTeacher ? "Versements reçus" : "Historique de salaire"}
                    </h2>
                    {transactions.length === 0 ? (
                        <div className="bg-white rounded-md border border-gray-100 shadow-sm p-10 text-center text-sm text-gray-400">
                            Aucun paiement enregistré pour le moment.
                        </div>
                    ) : (
                        <AssistantPaymentsCard transactions={transactions} userId={userId} />
                    )}
                </section>
            </div>
        </>
    );
}

MyPaymentsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
