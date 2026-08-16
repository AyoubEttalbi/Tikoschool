import { useState } from "react";
import { router } from "@inertiajs/react";

/*
 * "Envoyer WhatsApp" — say what already happened, and then what you just did.
 *
 * Two separate problems lived in this button.
 *
 * 1. It reported success for things that were not sends. Inertia's onSuccess fires on any
 *    successful visit, including a redirect carrying a refusal, so "queued", "already
 *    notified" and "no usable guardian number" all rendered as the same green checkmark.
 *    The server flashed `warning` / `error` for the last two and no page rendered them.
 *
 * 2. It knew nothing until you pressed it. An absence already reported by the register
 *    looked identical to one nobody had touched, so staff pressed the button to find out —
 *    and each press put a second copy of the same notice in front of a real parent. The
 *    row now arrives carrying its own answer (`notification`), so the button is already
 *    settled on first paint and there is nothing to discover by clicking.
 *
 * The label is "En file" and not "Envoyé" on the happy path, because that is the whole of
 * what this request knows: the notice is queued, and a worker delivers it seconds later —
 * or holds it, if the school's phone is disconnected. It becomes "Déjà envoyé" once the
 * send actually happened.
 *
 * One component, both call sites: AbsenceLogTable (/absence-log) and
 * AbsenceLogTableForStudent (the student profile).
 */

const VIEW = {
    sent: {
        label: "Déjà envoyé",
        button: "bg-green-100 text-green-800 border border-green-300",
        note: "text-green-700",
        icon: "check",
    },
    pending: {
        label: "En file",
        button: "bg-sky-100 text-sky-800 border border-sky-300",
        note: "text-sky-700",
        icon: "clock",
    },
    /*
     * The register records notices without sending them (the approval gate), so this
     * state is not a fact to display but an action waiting to happen: pressing the
     * button is the approval, and the server releases exactly this notice.
     */
    awaiting_approval: {
        label: "À valider",
        button: "bg-amber-100 text-amber-800 border border-amber-300",
        note: "text-amber-700",
        icon: "clock",
    },
    held: {
        label: "En attente",
        button: "bg-amber-100 text-amber-800 border border-amber-300",
        note: "text-amber-700",
        icon: "clock",
    },
    duplicate: {
        label: "Déjà envoyé",
        button: "bg-amber-100 text-amber-800 border border-amber-300",
        note: "text-amber-700",
        icon: "alert",
    },
    failed: {
        label: "Non envoyé",
        button: "bg-red-100 text-red-800 border border-red-300",
        note: "text-red-700",
        icon: "alert",
    },
};

// Statuses that all mean the same thing to somebody looking at an absence row: no notice
// reached this parent, and here is why.
const NOT_SENT = ["failed", "skipped", "expired"];

// Only a transport error resets. Every other outcome is a fact about the row that stays
// true until the page is reloaded — reverting it to "Envoyer" would just invite a click
// the server is going to refuse.
const RETRY_AFTER_MS = 6000;

const WhatsAppButton = ({
    studentId,
    studentName,
    attendanceId = null,
    notification = null,
    onSettled,
    className = "",
}) => {
    const [isLoading, setIsLoading] = useState(false);
    const [outcome, setOutcome] = useState(null);

    // What the server already knows about this absence, used until this session does
    // something that changes it.
    const fromServer = notification
        ? {
              kind: NOT_SENT.includes(notification.status)
                  ? "failed"
                  : notification.status,
              text: notification.reason ?? null,
          }
        : null;

    /*
     * An awaiting notice is the one server state that is NOT settled: it exists to be
     * pressed. Everything else the server knows (sent, skipped, failed…) is a fact that
     * must not be re-clicked, so it locks the button.
     */
    const awaiting = fromServer?.kind === "awaiting_approval";
    const settled = awaiting ? null : (outcome ?? fromServer);
    const view = awaiting
        ? VIEW.awaiting_approval
        : settled
          ? (VIEW[settled.kind] ?? VIEW.failed)
          : null;

    const handleSendWhatsApp = () => {
        if (isLoading || settled) return;

        setIsLoading(true);

        /*
         * The absence id, not just the pupil. This button sits on a row of the absence log,
         * so it means "tell this parent about THIS absence" — and sending the id lets the
         * server key the notice exactly as the register does, instead of inventing a
         * second key for the same event and mailing the parent a duplicate.
         */
        router.post(
            route("absence.notify", studentId),
            attendanceId ? { attendance_id: attendanceId } : {},
            {
                // Nothing on this page depends on the result, so re-rendering the table
                // from scratch and jumping to the top would be pure loss.
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    // A redirect back is "success" at the HTTP level whatever it carries.
                    // The flash is the only place the real outcome is written down.
                    const flash = page?.props?.flash ?? {};

                    if (flash.warning) {
                        setOutcome({ kind: "duplicate", text: flash.warning });
                    } else if (flash.error) {
                        setOutcome({ kind: "failed", text: flash.error });
                    } else {
                        setOutcome({ kind: "pending", text: null });
                    }

                    // The page above (the waiting bar's count, other rows) only knows
                    // the truth from the server; this settles it without a reload.
                    if (typeof onSettled === "function") onSettled();
                },
                onError: (errors) => {
                    const first = errors && Object.values(errors)[0];
                    setOutcome({
                        kind: "failed",
                        text: typeof first === "string" ? first : null,
                    });
                    setTimeout(() => setOutcome(null), RETRY_AFTER_MS);
                },
                onFinish: () => setIsLoading(false),
            },
        );
    };

    return (
        <div className="inline-flex flex-col items-start gap-1">
            <button
                onClick={handleSendWhatsApp}
                disabled={isLoading || Boolean(settled)}
                aria-live="polite"
                className={`
                    relative inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium
                    transition-all duration-200 ease-in-out
                    focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2
                    ${
                        awaiting
                            ? `${VIEW.awaiting_approval.button} transform cursor-pointer shadow-md hover:scale-105 hover:shadow-lg active:scale-95`
                            : view
                              ? `${view.button} cursor-default`
                              : isLoading
                                ? "cursor-not-allowed bg-green-500 text-white opacity-75"
                                : "transform bg-green-500 text-white shadow-md hover:scale-105 hover:bg-green-600 hover:shadow-lg active:scale-95"
                    }
                    ${className}
                `}
                title={
                    settled?.text ||
                    (awaiting
                        ? `Valider l'envoi au parent de ${studentName}`
                        : view
                          ? view.label
                          : `Envoyer un message WhatsApp au parent de ${studentName}`)
                }
            >
                <svg
                    className={`h-4 w-4 ${isLoading ? "animate-spin" : ""}`}
                    fill="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    {isLoading ? (
                        <circle
                            cx="12"
                            cy="12"
                            r="10"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeDasharray="31.416"
                            strokeDashoffset="31.416"
                        />
                    ) : view?.icon === "check" ? (
                        <path
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    ) : view?.icon === "clock" ? (
                        <path
                            d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    ) : view ? (
                        <path
                            d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    ) : (
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488" />
                    )}
                </svg>

                <span className="whitespace-nowrap">
                    {view ? view.label : isLoading ? "Envoi..." : "Envoyer WhatsApp"}
                </span>
            </button>

            {/* The reason, in full. A refusal is only useful if you can read why, and a
                title attribute is invisible on touch — which is where this table is
                mostly used. Not shown for a plain success; "En file" says it all. */}
            {settled?.text && settled.kind !== "pending" && (
                <span className={`max-w-[16rem] text-xs leading-tight ${view.note}`}>
                    {settled.text}
                </span>
            )}
        </div>
    );
};

export default WhatsAppButton;
