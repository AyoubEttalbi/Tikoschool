import React, { useEffect, useState, useRef } from "react";
import axios from "axios";
import { X, Loader2, Download, Search } from "lucide-react";

const TeacherEarningsDetailModal = ({ teacherId, teacherName, month, open, onClose }) => {
    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState("");
    const modalRef = useRef();

    useEffect(() => {
        if (!open) return;
        setLoading(true);
        setError(null);
        axios
            .get("/teacher-invoice-breakdown", {
                params: { teacher_id: teacherId, month },
            })
            .then((res) => setData(res.data))
            .catch(() => setError("Erreur lors du chargement."))
            .finally(() => setLoading(false));
    }, [teacherId, month, open]);

    // Close on outside click
    useEffect(() => {
        if (!open) return;
        const handleClick = (e) => {
            if (modalRef.current && !modalRef.current.contains(e.target)) {
                onClose();
            }
        };
        document.addEventListener("mousedown", handleClick);
        return () => document.removeEventListener("mousedown", handleClick);
    }, [open, onClose]);

    // Filtered data
    const filtered = Array.isArray(data) ? data.filter(
        (row) =>
            row.studentName.toLowerCase().includes(search.toLowerCase()) ||
            row.offerName.toLowerCase().includes(search.toLowerCase())
    ) : [];

    // Show backend error if present
    if (data && !Array.isArray(data) && data.error) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
                <div className="bg-white rounded-xl shadow-lg max-w-2xl w-full p-6 relative animate-fadeIn">
                    <button
                        className="absolute top-4 right-4 text-gray-500 hover:text-black"
                        onClick={onClose}
                        aria-label="Fermer"
                    >
                        <X className="w-6 h-6" />
                    </button>
                    <h2 className="text-lg font-bold mb-2">Erreur</h2>
                    <div className="text-red-500 text-center py-4">{data.error}</div>
                </div>
            </div>
        );
    }

    // Export to CSV
    const handleExport = () => {
        const header = "ID Facture,Date,Élève,Offre,Montant payé,Part enseignant\n";
        const rows = filtered.map(
            (row) =>
                `"${row.invoiceId}","${row.date}","${row.studentName}","${row.offerName}","${row.amountPaid} DH","${row.teacherShare} DH"`
        );
        const csv = header + rows.join("\n");
        const blob = new Blob([csv], { type: "text/csv" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `detail_gains_${teacherName}_${month}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
            <div
                ref={modalRef}
                className="bg-white rounded-xl shadow-lg max-w-2xl w-full p-6 relative animate-fadeIn"
            >
                <button
                    className="absolute top-4 right-4 text-gray-500 hover:text-black"
                    onClick={onClose}
                    aria-label="Fermer"
                >
                    <X className="w-6 h-6" />
                </button>
                <h2 className="text-lg font-bold mb-2">
                    Détail des gains — {teacherName} ({month})
                </h2>
                <div className="flex items-center gap-2 mb-4">
                    <Search className="w-4 h-4 text-gray-400" />
                    <input
                        type="text"
                        className="border rounded-md px-2 py-1 text-sm flex-1"
                        placeholder="Rechercher élève ou offre..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <button
                        className="flex items-center gap-2 px-3 py-1 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
                        onClick={handleExport}
                    >
                        <Download className="w-4 h-4" /> Exporter CSV
                    </button>
                </div>
                {loading ? (
                    <div className="flex justify-center items-center py-8">
                        <Loader2 className="animate-spin w-6 h-6 text-blue-500" />
                    </div>
                ) : error ? (
                    <div className="text-red-500 text-center py-4">{error}</div>
                ) : (
                    // Debugging: log data and filtered
                    console.log('Modal data:', data, 'Filtered:', filtered),
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="bg-gray-100">
                                    <th className="p-2 text-left">ID Facture</th>
                                    <th className="p-2 text-left">Date</th>
                                    <th className="p-2 text-left">Élève</th>
                                    <th className="p-2 text-left">Offre</th>
                                    <th className="p-2 text-right">Montant payé</th>
                                    <th className="p-2 text-right">Part enseignant</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="text-center py-8 text-gray-400">
                                            Aucun résultat trouvé.
                                        </td>
                                    </tr>
                                ) : (
                                    filtered.map((row, i) => (
                                        <tr key={i} className={i % 2 === 0 ? "bg-white" : "bg-gray-50"}>
                                            <td className="p-2">{row.invoiceId}</td>
                                            <td className="p-2">{row.date}</td>
                                            <td className="p-2">{row.studentName}</td>
                                            <td className="p-2">{row.offerName}</td>
                                            <td className="p-2 text-right">{row.amountPaid} DH</td>
                                            <td className="p-2 text-right text-blue-700 font-semibold">{row.teacherShare} DH</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};

export default TeacherEarningsDetailModal; 