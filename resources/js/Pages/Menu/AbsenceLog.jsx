import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AbsenceLogTable from '../../Components/AbsenceLogTable';
import DashboardLayout from '@/Layouts/DashboardLayout';
import TextInput from '@/Components/TextInput';
import PaymentsPagination from '@/Components/PaymentsPagination';
import { WifiOff, AlertTriangle } from 'lucide-react';

// Helper to get today's date in YYYY-MM-DD format
const getToday = () => {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
};

const AbsenceLog = () => {
    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({ date: "", period: "last_7_days" });
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
    // Notices the register recorded without sending, for the day being viewed. This page
    // is the review surface: fix what is wrong, then release the rest in one motion.
    const [awaiting, setAwaiting] = useState(0);
    const initialGateway = usePage().props.gateway ?? null;
    const [gateway, setGateway] = useState(initialGateway);
    const [releasing, setReleasing] = useState(false);
    const { flash } = usePage().props;

    const fetchData = async (page = 1, filterParams = filters) => {
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ ...filterParams, page });
            // no-store: after "valider et envoyer tout" the same URL is fetched again,
            // and a cached answer would keep showing "À valider" rows until a manual
            // reload — exactly the report this page got.
            const res = await fetch(`/api/absence-log?${params.toString()}`, {
                cache: "no-store",
            });
            if (!res.ok) throw new Error('Erreur lors du chargement des absences');
            const json = await res.json();
            setData(json.data.data); // <-- Only the array of records
            setAwaiting(json.awaiting ?? 0);
            if (json.gateway) setGateway(json.gateway);
            setPagination({
                current_page: json.data.current_page,
                last_page: json.data.last_page,
                per_page: json.data.per_page,
                total: json.data.total,
            });

        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData(1, filters);
        // eslint-disable-next-line
    }, []);

    // When date, period or status changes, auto-filter
    useEffect(() => {
        fetchData(1, filters);
    }, [filters.date, filters.period, filters.status]);

    const handleDateChange = (e) => {
        const val = e.target.value;
        setFilters((prev) => ({ ...prev, date: val, period: val ? "" : prev.period }));
    };

    const handlePageChange = (page) => {
        fetchData(page, filters);
    };

    // "Valider et envoyer tout" — approve and queue every waiting notice for the day
    // shown. The result arrives as a flash; the refetch turns the row buttons from
    // "À valider" to "En file".
    const releaseAll = () => {
        if (releasing) return;
        setReleasing(true);
        router.post(
            route('absence.notifications.release'),
            { date: filters.date },
            {
                preserveScroll: true,
                onFinish: () => {
                    setReleasing(false);
                    fetchData(pagination.current_page, filters);
                },
            },
        );
    };

    /*
     * After a per-row validation the button settles itself, but the rest of the page
     * still thinks a notice is waiting: the amber bar's count and any other rows only
     * change on the server's word. One quiet refetch — without the loading flash, so
     * the table does not blink out from under the reader.
     */
    const refreshQuietly = () => {
        fetch(`/api/absence-log?${new URLSearchParams({ ...filters, page: pagination.current_page })}`, {
            cache: "no-store",
        })
            .then((res) => (res.ok ? res.json() : null))
            .then((json) => {
                if (!json) return;
                setData(json.data.data);
                setAwaiting(json.awaiting ?? 0);
                if (json.gateway) setGateway(json.gateway);
                setPagination((prev) => ({ ...prev, total: json.data.total, last_page: json.data.last_page, current_page: json.data.current_page }));
            })
            .catch(() => {});
    };

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 lg:px-12">
            <Head title="Absence Log" />
            <div className="flex items-center justify-between mb-4 " >
                <div>
                    <h1 className="text-lg font-semibold">Journal des absences et retards</h1>
                    <p className="text-xs text-gray-500 mt-1">
                        {pagination.total} enregistrement{pagination.total > 1 ? 's' : ''}
                        {filters.period === 'last_7_days' && ' • 7 derniers jours'}
                        {filters.date && ` • ${filters.date}`}
                        {' • '}{awaiting} à valider
                    </p>
                </div>
                <div className="flex flex-wrap gap-4 items-end">
                    <div>
                        <label className="block text-sm font-medium mb-1">Date</label>
                        <div className="flex items-center gap-2">
                            <TextInput
                                type="date"
                                name="date"
                                value={filters.date}
                                onChange={handleDateChange}
                                className="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm px-2 py-1"
                            />
                            {/* Vérifier la journée en cours doit être un clic, pas une
                                saisie de date — c'était le reproche fait à cette page. */}
                            <button
                                type="button"
                                onClick={() =>
                                    setFilters((prev) => ({ ...prev, period: "last_7_days", date: "" }))
                                }
                                className={`text-xs font-medium px-2.5 py-2 rounded-md border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky ${
                                    filters.period === "last_7_days" && !filters.date
                                        ? "bg-lamaSky text-white border-lamaSky"
                                        : "bg-white text-gray-600 border-gray-300 hover:bg-gray-50"
                                }`}
                            >
                                7 derniers jours
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setFilters((prev) => ({ ...prev, date: getToday(), period: "" }))
                                }
                                className={`text-xs font-medium px-2.5 py-2 rounded-md border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky ${
                                    filters.date === getToday()
                                        ? "bg-lamaSky text-white border-lamaSky"
                                        : "bg-white text-gray-600 border-gray-300 hover:bg-gray-50"
                                }`}
                            >
                                Aujourd'hui
                            </button>
                            {(filters.date || filters.period) && (
                                <button
                                    type="button"
                                    onClick={() => setFilters((prev) => ({ ...prev, date: "", period: "" }))}
                                    className="text-xs text-gray-400 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 rounded px-1"
                                    title="Afficher toutes les dates"
                                >
                                    Tout
                                </button>
                            )}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">Statut</label>
                        <select
                            name="status"
                            value={filters.status || ""}
                            onChange={(e) =>
                                setFilters((prev) => ({
                                    ...prev,
                                    status: e.target.value || undefined,
                                }))
                            }
                            className="border border-gray-300 rounded-md shadow-sm px-2 py-1 text-sm"
                        >
                            <option value="">Tous</option>
                            <option value="absent">Absents</option>
                            <option value="late">Retards</option>
                            <option value="present">Présents</option>
                        </select>
                    </div>
                </div>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.success}
                </div>
            )}
            {flash?.warning && (
                <div className="mb-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {flash.warning}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            {gateway && !gateway.canSend && (
                <div className="mb-4 flex items-center gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3">
                    <WifiOff className="h-5 w-5 text-red-500 flex-shrink-0" />
                    <div className="text-sm text-red-800">
                        <p className="font-medium">Passerelle WhatsApp déconnectée ({gateway.state}) — envoi impossible</p>
                        <p className="text-xs mt-0.5">Vous devez contacter l'administrateur pour reconnecter WhatsApp. Les notifications resteront en attente jusqu'à la reconnexion.</p>
                    </div>
                </div>
            )}
            {awaiting > 0 && gateway?.canSend && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm text-amber-900">
                        <b>{awaiting}</b> notification{awaiting > 1 ? 's' : ''} en attente de
                        validation — vérifiez les absences, puis envoyez.
                    </p>
                    <button
                        type="button"
                        onClick={releaseAll}
                        disabled={releasing || loading}
                        className="inline-flex items-center gap-2 rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50"
                    >
                        {releasing ? 'Envoi…' : 'Valider et envoyer tout'}
                    </button>
                </div>
            )}
            {awaiting > 0 && gateway && !gateway.canSend && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 opacity-75">
                    <p className="text-sm text-amber-900">
                        <b>{awaiting}</b> notification{awaiting > 1 ? 's' : ''} en attente — envoi bloqué par la passerelle déconnectée.
                    </p>
                    <button
                        type="button"
                        disabled
                        className="inline-flex items-center gap-2 rounded-md bg-gray-300 px-4 py-2 text-sm font-medium text-gray-600 shadow-sm cursor-not-allowed"
                        title="Passerelle déconnectée — contactez l'administrateur"
                    >
                        <AlertTriangle className="h-4 w-4" />
                        Envoi bloqué
                    </button>
                </div>
            )}

            {loading ? (
                <div>Chargement...</div>
            ) : error ? (
                <div className="text-red-500">{error}</div>
            ) : (
                <>
                    <AbsenceLogTable
                        absences={data}
                        pagination={pagination}
                        onPageChange={handlePageChange}
                        onNotified={refreshQuietly}
                    />
                    {pagination.last_page > 1 && (
                        <div className="mt-4 flex flex-col sm:flex-row items-center justify-between gap-2">
                            <span className="text-xs text-gray-500">
                                Page {pagination.current_page} sur {pagination.last_page} • {pagination.total} au total
                            </span>
                            <PaymentsPagination
                                currentPage={pagination.current_page}
                                totalPages={pagination.last_page}
                                onPageChange={handlePageChange}
                            />
                        </div>
                    )}
                </>
            )}
        </div>
    );
};

AbsenceLog.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default AbsenceLog; 