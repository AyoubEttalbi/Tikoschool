import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AbsenceLogTable from '../../Components/AbsenceLogTable';
import DashboardLayout from '@/Layouts/DashboardLayout';
import TextInput from '@/Components/TextInput';

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
    const [filters, setFilters] = useState({ date: "" });
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
    // Notices the register recorded without sending, for the day being viewed. This page
    // is the review surface: fix what is wrong, then release the rest in one motion.
    const [awaiting, setAwaiting] = useState(0);
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

    // When date or status changes, auto-filter
    useEffect(() => {
        fetchData(1, filters);
    }, [filters.date, filters.status]);

    const handleDateChange = (e) => {
        setFilters((prev) => ({ ...prev, date: e.target.value }));
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
                setPagination((prev) => ({ ...prev, total: json.data.total, last_page: json.data.last_page }));
            })
            .catch(() => {});
    };

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 lg:px-12">
            <Head title="Absence Log" />
            <div className="flex items-center justify-between mb-4 " >
                <h1 className="text-lg font-semibold">Journal des absences et retards</h1>
                <div className="flex flex-wrap gap-4 items-end">
                    <div>
                        <label className="block text-sm font-medium mb-1">Date</label>
                        <TextInput
                            type="date"
                            name="date"
                            value={filters.date}
                            onChange={handleDateChange}
                            className="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm px-2 py-1"
                        />
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

            {awaiting > 0 && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm text-amber-900">
                        <b>{awaiting}</b> notification{awaiting > 1 ? 's' : ''} en attente de
                        validation pour cette journée — vérifiez les absences, puis envoyez.
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

            {loading ? (
                <div>Chargement...</div>
            ) : error ? (
                <div className="text-red-500">{error}</div>
            ) : (
                <AbsenceLogTable
                    absences={data}
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onNotified={refreshQuietly}
                />
            )}
        </div>
    );
};

AbsenceLog.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default AbsenceLog; 