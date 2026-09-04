import React, { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { AlertTriangle, CheckCircle2, XCircle, X, Lock } from "lucide-react";

/**
 * Shows what an action did to teacher money, and what it could not do.
 *
 * Mounted ONCE in DashboardLayout, so every controller that flashes `payment_notice` gets a
 * dialog without touching the page. It reads `flash.payment` — see App\Support\PaymentNotice
 * for the payload and HandleInertiaRequests for where it is shared.
 *
 * WHY A DIALOG AND NOT A BANNER
 * -----------------------------
 * These messages report irreversible consequences: "the invoice is gone, but Majid keeps
 * 240 DH because it was billed 12 days ago." A banner at the top of a long page is missed,
 * and disappears on the next navigation, so nobody finds out until the month-end payout is
 * wrong. This has to be dismissed deliberately.
 *
 * Ordinary successes do NOT open it — App\Support\PaymentNotice::fromReversal() returns null
 * when nothing happened worth interrupting for. A dialog that appears after every routine
 * save is a dialog people click through without reading.
 */

const TONES = {
    success: {
        Icon: CheckCircle2,
        ring: "ring-emerald-200",
        iconWrap: "bg-emerald-50 text-emerald-600",
        heading: "text-emerald-900",
        button: "bg-emerald-600 hover:bg-emerald-700 focus-visible:outline-emerald-600",
    },
    warning: {
        Icon: AlertTriangle,
        ring: "ring-amber-200",
        iconWrap: "bg-amber-50 text-amber-600",
        heading: "text-amber-900",
        button: "bg-amber-600 hover:bg-amber-700 focus-visible:outline-amber-600",
    },
    error: {
        Icon: XCircle,
        ring: "ring-red-200",
        iconWrap: "bg-red-50 text-red-600",
        heading: "text-red-900",
        button: "bg-red-600 hover:bg-red-700 focus-visible:outline-red-600",
    },
};

export default function PaymentNoticeDialog() {
    const notice = usePage().props?.flash?.payment ?? null;
    const [open, setOpen] = useState(false);

    // Keyed on the notice itself rather than a boolean: a second action that flashes another
    // notice must reopen the dialog even if the user just dismissed the previous one.
    useEffect(() => {
        setOpen(Boolean(notice));
    }, [notice]);

    useEffect(() => {
        if (!open) return undefined;

        const onKeyDown = (event) => {
            if (event.key === "Escape") setOpen(false);
        };

        document.addEventListener("keydown", onKeyDown);
        return () => document.removeEventListener("keydown", onKeyDown);
    }, [open]);

    if (!open || !notice) return null;

    const tone = TONES[notice.tone] ?? TONES.warning;
    const { Icon } = tone;
    const messages = notice.messages ?? [];
    const details = notice.details ?? [];
    const actions = notice.actions ?? [];

    // Fired from a dialog the user opened by pressing delete, so the close has to happen
    // before the request: leaving it open would let a second click send the same
    // irreversible action twice while the first is still in flight.
    const runAction = (action) => {
        setOpen(false);

        const options = {
            data: action.data ?? {},
            preserveScroll: true,
        };

        if (action.method === "delete") {
            router.delete(action.url, options);
        } else if (action.method === "put") {
            // Membership teacher-change confirm: resubmits the full payload plus the
            // confirm flag, so the server re-validates from scratch.
            router.put(action.url, action.data ?? {}, { preserveScroll: true });
        } else {
            router.post(action.url, action.data ?? {}, { preserveScroll: true });
        }
    };

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="payment-notice-title"
        >
            <div
                className="absolute inset-0 bg-slate-900/50 backdrop-blur-[1px]"
                onClick={() => setOpen(false)}
                aria-hidden="true"
            />

            <div
                className={`relative w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ${tone.ring}`}
            >
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    aria-label="Fermer"
                    className="absolute right-3 top-3 rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                >
                    <X className="h-5 w-5" />
                </button>

                <div className="flex gap-4 p-6 pb-4">
                    <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${tone.iconWrap}`}>
                        <Icon className="h-6 w-6" aria-hidden="true" />
                    </div>

                    <div className="min-w-0 flex-1 pr-6">
                        <h2
                            id="payment-notice-title"
                            className={`text-base font-semibold ${tone.heading}`}
                        >
                            {notice.title}
                        </h2>

                        {messages.length > 0 && (
                            <div className="mt-2 space-y-2 text-sm leading-relaxed text-slate-600">
                                {messages.map((message, index) => (
                                    <p key={index}>{message}</p>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {details.length > 0 && (
                    /* Capped and scrollable: a membership with many teachers must not push
                       the dismiss button off the bottom of the screen. */
                    <div className="max-h-64 overflow-y-auto border-t border-slate-100 bg-slate-50/60 px-6 py-3">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-slate-200/70">
                                {details.map((row, index) => (
                                    <tr key={index}>
                                        <td className="py-2 pr-3 text-slate-700">
                                            {row.label}
                                        </td>
                                        <td className="whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums text-slate-900">
                                            {row.value}
                                        </td>
                                        <td className="py-2 text-right text-xs text-slate-500">
                                            {row.note}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {notice.admin_note && (
                    /* Sits UNDER the table, addressed to the admin reading it. Nobody else
                       receives this — telling a user that a detail exists but is hidden from
                       them is noise they cannot act on. */
                    <div className="flex items-center gap-1.5 bg-slate-50/60 px-6 pb-3 text-xs text-slate-400">
                        <Lock className="h-3 w-3 shrink-0" aria-hidden="true" />
                        <span>{notice.admin_note}</span>
                    </div>
                )}

                <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 px-6 py-4">
                    {/* Always first and focused: the safe choice is the default one. */}
                    <button
                        type="button"
                        onClick={() => setOpen(false)}
                        autoFocus
                        className={
                            actions.length > 0
                                ? "rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
                                : `rounded-md px-4 py-2 text-sm font-medium text-white transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${tone.button}`
                        }
                    >
                        J'ai compris
                    </button>

                    {actions.map((action, index) => (
                        <button
                            key={index}
                            type="button"
                            onClick={() => runAction(action)}
                            className={`rounded-md px-4 py-2 text-sm font-medium text-white transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
                                action.style === "danger"
                                    ? "bg-red-600 hover:bg-red-700 focus-visible:outline-red-600"
                                    : tone.button
                            }`}
                        >
                            {action.label}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
