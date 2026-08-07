import React, { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Wallet, Lock } from "lucide-react";

/**
 * Read the balance; change it deliberately.
 *
 * This replaces the plain "Solde du portefeuille" number input that used to sit on the
 * teacher form. That field re-submitted whatever balance the page had loaded, so saving an
 * unrelated field — a phone number, a school assignment — silently reverted any earnings
 * credited while the form was open. It was a lost update on money.
 *
 * Changing a balance is now its own action with its own reason, posted to
 * teachers.wallet.adjust, which records the movement in the wallet ledger. Admin only,
 * because it moves money; everyone else sees the balance and why they cannot change it.
 */
export default function TeacherWalletPanel({ teacher }) {
    const role = usePage().props?.auth?.user?.role;
    const isAdmin = role === "admin";

    const current = Number(teacher?.wallet ?? 0);

    const [open, setOpen] = useState(false);
    const [newBalance, setNewBalance] = useState(String(current));
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const format = (value) =>
        new Intl.NumberFormat("fr-MA", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(value || 0));

    const parsed = Number(newBalance);
    const delta = Number.isFinite(parsed) ? parsed - current : 0;
    const canSubmit =
        Number.isFinite(parsed) && parsed >= 0 && note.trim().length >= 3 && !saving;

    const submit = () => {
        if (!canSubmit) return;

        setSaving(true);
        setError(null);

        router.post(
            `/teachers/${teacher.id}/wallet`,
            { new_balance: parsed, note: note.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setNote("");
                },
                // The server reports the real outcome through the payment-notice dialog, so
                // this only has to surface validation refusals.
                onError: (errors) =>
                    setError(
                        errors?.new_balance ||
                            errors?.note ||
                            "L'ajustement n'a pas pu être enregistré.",
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="flex w-full flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Wallet className="h-4 w-4 text-slate-400" aria-hidden="true" />
                    <div>
                        <div className="text-xs text-gray-500">
                            Solde du portefeuille
                        </div>
                        <div className="text-lg font-semibold tabular-nums text-slate-900">
                            {format(current)} DH
                        </div>
                    </div>
                </div>

                {isAdmin ? (
                    <button
                        type="button"
                        onClick={() => {
                            setOpen((v) => !v);
                            setNewBalance(String(current));
                            setError(null);
                        }}
                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        {open ? "Annuler" : "Ajuster"}
                    </button>
                ) : (
                    <span className="flex items-center gap-1 text-xs text-slate-400">
                        <Lock className="h-3 w-3" aria-hidden="true" />
                        Administrateur uniquement
                    </span>
                )}
            </div>

            {isAdmin && open && (
                <div className="flex flex-col gap-2 border-t border-slate-200 pt-3">
                    <p className="text-xs leading-relaxed text-slate-500">
                        Le solde est calculé à partir du journal des paiements. Un
                        ajustement manuel y est enregistré avec sa raison ; il ne
                        remplace pas le journal.
                    </p>

                    <label className="flex flex-col gap-1">
                        <span className="text-xs text-gray-500">Nouveau solde (DH)</span>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            value={newBalance}
                            onChange={(e) => setNewBalance(e.target.value)}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                        />
                    </label>

                    <label className="flex flex-col gap-1">
                        <span className="text-xs text-gray-500">
                            Raison de l'ajustement (obligatoire)
                        </span>
                        <input
                            type="text"
                            value={note}
                            maxLength={255}
                            placeholder="ex. régularisation paiement de septembre"
                            onChange={(e) => setNote(e.target.value)}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                        />
                    </label>

                    {Number.isFinite(parsed) && delta !== 0 && (
                        <div className="text-xs text-slate-600">
                            Mouvement enregistré :{" "}
                            <span
                                className={`font-semibold tabular-nums ${delta > 0 ? "text-emerald-700" : "text-red-700"}`}
                            >
                                {delta > 0 ? "+" : "−"}
                                {format(Math.abs(delta))} DH
                            </span>
                        </div>
                    )}

                    {error && <div className="text-xs text-red-600">{error}</div>}

                    <button
                        type="button"
                        onClick={submit}
                        disabled={!canSubmit}
                        className="self-start rounded-md bg-purple-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-purple-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                        {saving ? "Enregistrement…" : "Enregistrer l'ajustement"}
                    </button>
                </div>
            )}
        </div>
    );
}
