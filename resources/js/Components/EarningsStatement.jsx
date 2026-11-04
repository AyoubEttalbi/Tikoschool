import React from "react";
import { CheckCircle2, AlertTriangle } from "lucide-react";

/*
 * Pieces of the teacher earnings statement (App\Support\TeacherEarnings),
 * shared by « Gains Mensuels » (teacher profile) and « Mes gains » (payroll).
 * Every amount comes from the wallet ledger, so the strip always reads
 * Gagné à ce jour − Versé = Solde, and the Solde is the wallet.
 */

const dh = (value) =>
    `${Number(value || 0).toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} DH`;

export const signedDh = (value) => {
    const n = Number(value || 0);
    return `${n < 0 ? "−" : "+"} ${dh(Math.abs(n))}`;
};

const STATUS = {
    paid: { label: "Payé", className: "bg-green-100 text-green-800" },
    upcoming: { label: "À venir", className: "bg-sky-100 text-sky-800" },
    owed: { label: "Reste dû", className: "bg-amber-100 text-amber-800" },
    kept_removed: { label: "Conservé — retiré de l'adhésion", className: "bg-violet-100 text-violet-800" },
    kept_deleted: { label: "Conservé — facture supprimée", className: "bg-violet-100 text-violet-800" },
    history: { label: "Historique (avant registre)", className: "bg-gray-100 text-gray-600" },
};

export function EarningsStatusBadge({ row }) {
    if (!row) return null;

    if (row.status === "adjustment") {
        const positive = Number(row.teacher_amount) >= 0;
        return (
            <span
                className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                    positive ? "bg-emerald-50 text-emerald-800" : "bg-amber-100 text-amber-800"
                }`}
                title={row.label || undefined}
            >
                {row.label || (positive ? "Écart" : "Reste dû")}
            </span>
        );
    }

    const status = STATUS[row.status] ?? (row.is_month_paid ? STATUS.paid : STATUS.upcoming);

    return (
        <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${status.className}`}>
            {status.label}
        </span>
    );
}

/** Amount colour: what is earned is green, what is not yet (or owed back) is muted / amber. */
export const amountClass = (row) => {
    if (!row) return "";
    if (Number(row.teacher_amount) < 0) return "text-amber-700";
    if (row.status === "upcoming" || row.status === "history") return "text-gray-400";
    return "text-green-600";
};

export function EarningsSummaryStrip({ summary }) {
    if (!summary) return null;

    const items = [
        { label: "Gagné à ce jour", value: dh(summary.earned_to_date), hint: "Toutes les commissions reçues, ajustements compris" },
        { label: "Versé", value: dh(summary.paid_out), hint: "Paiements remis à l'enseignant" },
        { label: "Solde", value: dh(summary.balance), hint: "Gagné − Versé" },
        { label: "À venir", value: dh(summary.upcoming), hint: "Mois futurs déjà facturés, non comptés" },
    ];

    return (
        <div className="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                {items.map((item) => (
                    <div key={item.label} title={item.hint}>
                        <p className="text-xs text-gray-500 font-medium">{item.label}</p>
                        <p className="text-lg font-bold text-gray-900 tabular-nums">{item.value}</p>
                    </div>
                ))}
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-3 text-xs">
                {summary.reconciles ? (
                    <span className="inline-flex items-center gap-1 text-green-700">
                        <CheckCircle2 className="w-4 h-4" aria-hidden="true" />
                        Le solde correspond au portefeuille ({dh(summary.wallet)})
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 text-red-700 font-semibold">
                        <AlertTriangle className="w-4 h-4" aria-hidden="true" />
                        Écart avec le portefeuille ({dh(summary.wallet)}) — à signaler
                    </span>
                )}
                {Number(summary.owed) > 0 && (
                    <span className="text-amber-700">Reste dû sur des factures : {dh(summary.owed)}</span>
                )}
            </div>
        </div>
    );
}

const REASONS = {
    "manual.adjustment": "Ajustement",
    "invoice.reconcile": "Régularisation",
    "invoice.reversal": "Annulation",
    "invoice.immediate": "Commission",
    "schedule.monthly": "Commission mensuelle",
    "repair.shortfall": "Correction",
};

export function EarningsExtras({ other = [], anomalies = [] }) {
    if (other.length === 0 && anomalies.length === 0) return null;

    return (
        <div className="grid gap-4 md:grid-cols-2 mt-4">
            {other.length > 0 && (
                <div className="bg-white border border-gray-200 rounded-lg p-4">
                    <h3 className="text-sm font-semibold text-gray-800 mb-2">Autres mouvements</h3>
                    <p className="text-xs text-gray-500 mb-3">
                        Ajustements et soldes d'ouverture, comptés dans « Gagné à ce jour ».
                    </p>
                    <ul className="divide-y divide-gray-100 max-h-64 overflow-y-auto">
                        {other.map((m) => (
                            <li key={m.id} className="py-2 flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <p className="text-gray-800">{REASONS[m.reason] ?? m.reason}</p>
                                    <p className="text-xs text-gray-500">
                                        {m.date}
                                        {m.note ? ` — ${m.note}` : ""}
                                    </p>
                                </div>
                                <span className={`tabular-nums font-semibold ${m.amount < 0 ? "text-amber-700" : "text-green-600"}`}>
                                    {signedDh(m.amount)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {anomalies.length > 0 && (
                <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                    <h3 className="text-sm font-semibold text-red-800 mb-2 flex items-center gap-1">
                        <AlertTriangle className="w-4 h-4" aria-hidden="true" />
                        À vérifier
                    </h3>
                    <p className="text-xs text-red-700 mb-3">Non comptés dans les gains : une décision est nécessaire.</p>
                    <ul className="divide-y divide-red-100">
                        {anomalies.map((a, i) => (
                            <li key={`${a.kind}_${a.invoice_id}_${i}`} className="py-2 text-sm">
                                <p className="text-red-900">
                                    Facture #{a.invoice_id} — {a.student_name || "Élève"} ({a.subject})
                                </p>
                                <p className="text-xs text-red-700">
                                    {a.label} — attendu {dh(a.expected)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export function HistoryToggle({ checked, onChange }) {
    return (
        <label className="inline-flex items-center gap-2 text-xs text-gray-600 cursor-pointer select-none">
            <input
                type="checkbox"
                className="rounded"
                checked={!!checked}
                onChange={(e) => onChange(e.target.checked)}
            />
            Afficher l'historique (avant registre)
        </label>
    );
}
