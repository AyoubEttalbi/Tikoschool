import React, { useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { AlertTriangle, X, WifiOff } from "lucide-react";

/**
 * Admin reminder when WhatsApp gateway is not connected.
 * Mounted once in DashboardLayout — appears every login while state !== open.
 * Dismiss is per-mount; next login (full reload) will show again until reconnected.
 */
const STATE_LABEL = {
    logged_out: "Déconnecté",
    qr: "En attente de scan QR",
    unreachable: "Injoignable",
    open: "Connecté",
};

export default function WhatsAppGatewayDialog() {
    const { auth, gatewayStatus } = usePage().props;
    const role = auth?.user?.role;
    const state = gatewayStatus ?? null;

    const [open, setOpen] = useState(false);
    const hasAutoOpened = useRef(false);

    const shouldShow = role === "admin" && state && state !== "open" && state !== "n/a";

    useEffect(() => {
        if (shouldShow && !hasAutoOpened.current) {
            setOpen(true);
            hasAutoOpened.current = true;
        }
        // Auto-close when reconnected
        if (state === "open" || state === "n/a" || state === null) {
            setOpen(false);
            if (state === "open") hasAutoOpened.current = false;
        }
    }, [state, shouldShow]);

    useEffect(() => {
        if (!open) return undefined;
        const onKeyDown = (e) => {
            if (e.key === "Escape") setOpen(false);
        };
        document.addEventListener("keydown", onKeyDown);
        return () => document.removeEventListener("keydown", onKeyDown);
    }, [open]);

    if (!open || !shouldShow) return null;

    const label = STATE_LABEL[state] ?? state;

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4" role="dialog" aria-modal="true">
            <div className="w-full max-w-md rounded-2xl bg-white shadow-xl ring-1 ring-amber-200 overflow-hidden animate-in fade-in zoom-in">
                <div className="px-6 pt-6 pb-4">
                    <div className="flex items-start gap-4">
                        <div className="flex-shrink-0 mt-0.5">
                            <div className="h-10 w-10 rounded-full bg-amber-50 flex items-center justify-center text-amber-600 ring-1 ring-amber-200">
                                <WifiOff className="h-5 w-5" />
                            </div>
                        </div>
                        <div className="flex-1 min-w-0">
                            <h2 className="text-base font-semibold text-gray-900">WhatsApp déconnecté</h2>
                            <p className="mt-1 text-sm text-gray-600">
                                La passerelle WhatsApp est <span className="font-medium text-amber-700">{label}</span>. Aucun message d'absence ne peut être envoyé aux parents tant qu'elle n'est pas reconnectée.
                            </p>
                            <p className="mt-2 text-xs text-gray-500">
                                Reconnectez l'appareil depuis la page Notifications WhatsApp en scannant le QR code.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="text-gray-400 hover:text-gray-600 p-1 rounded-md"
                            aria-label="Fermer"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <div className="px-6 py-4 bg-gray-50 flex flex-wrap gap-2 justify-end">
                    <button
                        type="button"
                        onClick={() => setOpen(false)}
                        className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                    >
                        Fermer
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setOpen(false);
                            router.visit("/notifications");
                        }}
                        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                    >
                        <AlertTriangle className="h-4 w-4" />
                        Aller aux notifications
                    </button>
                </div>
            </div>
        </div>
    );
}
