import { Link, router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Pagination";
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    Clock,
    MinusCircle,
    PauseCircle,
    Power,
    RotateCcw,
    Settings2,
    XCircle,
} from "lucide-react";

/*
 * "Was this parent told?"
 *
 * The only question the school actually asks about notifications, and one it could not
 * answer before this screen: the outcome lived in a drained queue and a log file. Every
 * row here is one decision — including the decision NOT to send, which is the case staff
 * most need to see, because a missing guardian number looks identical to success from
 * every other screen in the app.
 *
 * The guardian's phone number and the message body are deliberately NOT sent to the
 * browser. Staff need to know a notice went out, not to read a child's absence notice.
 *
 * Layout note: this page is one column on purpose. It was two — a short gateway card
 * beside a ten-row settings list — which left a large hole under the card at every width
 * above `lg`, and pushed the table (the reason anyone opens the page) below the fold.
 * The connection state is now a strip, and the settings are a disclosure at the bottom:
 * they are reference values, read once, not a dashboard.
 */

const STATUS = {
    sent: {
        label: "Envoyé",
        chip: "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        tone: "text-emerald-600",
        Icon: CheckCircle2,
    },
    pending: {
        label: "En file",
        chip: "bg-sky-50 text-sky-700 ring-sky-600/20",
        tone: "text-sky-600",
        Icon: Clock,
    },
    held: {
        label: "En pause",
        chip: "bg-amber-50 text-amber-700 ring-amber-600/20",
        tone: "text-amber-600",
        Icon: PauseCircle,
    },
    failed: {
        label: "Échec",
        chip: "bg-red-50 text-red-700 ring-red-600/20",
        tone: "text-red-600",
        Icon: AlertTriangle,
    },
    skipped: {
        label: "Non envoyé",
        chip: "bg-slate-100 text-slate-600 ring-slate-500/20",
        tone: "text-slate-500",
        Icon: MinusCircle,
    },
    expired: {
        label: "Expiré",
        chip: "bg-slate-100 text-slate-500 ring-slate-400/20",
        tone: "text-slate-400",
        Icon: XCircle,
    },
};

/*
 * Selected state carries the STATUS colour rather than inverting to near-black.
 *
 * Six counters that all turn the same solid dark on selection say nothing about what was
 * selected, and the number — the only content on the control — loses the colour that gave
 * it meaning everywhere else on the page. Tinting instead keeps "échec" red whether or not
 * it is the active filter. Classes are written out in full because Tailwind reads them from
 * the source; a template-built class name is not in the stylesheet.
 */
const TABS = [
    {
        key: "all",
        label: "Toutes",
        on: "border-slate-400 bg-slate-50 ring-slate-300",
        num: "text-slate-900",
        txt: "text-slate-600",
    },
    {
        key: "sent",
        label: "Envoyées",
        on: "border-emerald-300 bg-emerald-50 ring-emerald-200",
        num: "text-emerald-700",
        txt: "text-emerald-800",
    },
    {
        key: "pending",
        label: "En file",
        on: "border-sky-300 bg-sky-50 ring-sky-200",
        num: "text-sky-700",
        txt: "text-sky-800",
    },
    {
        key: "held",
        label: "En pause",
        on: "border-amber-300 bg-amber-50 ring-amber-200",
        num: "text-amber-700",
        txt: "text-amber-800",
    },
    {
        key: "failed",
        label: "Échecs",
        on: "border-red-300 bg-red-50 ring-red-200",
        num: "text-red-700",
        txt: "text-red-800",
    },
    {
        key: "skipped",
        label: "Non envoyées",
        on: "border-slate-400 bg-slate-100 ring-slate-300",
        num: "text-slate-700",
        txt: "text-slate-600",
    },
];

const time = (iso) =>
    iso
        ? new Date(iso).toLocaleTimeString("fr-FR", {
              hour: "2-digit",
              minute: "2-digit",
          })
        : "—";

/*
 * The gateway states, in the school's language rather than the protocol's.
 *
 * `what` is the fact, `so` is the consequence. Staff do not need to know what "logged_out"
 * means in Baileys; they need to know that nothing is reaching parents and that a phone
 * has to scan something.
 */
const GATEWAY = {
    open: {
        label: "Connectée",
        dot: "bg-emerald-500",
        rail: "border-l-emerald-500",
        so: "Les messages partent normalement.",
    },
    qr: {
        label: "À connecter",
        dot: "bg-amber-500",
        rail: "border-l-amber-500",
        so: "Rien n'est envoyé. Les messages attendent — ils ne sont pas perdus.",
    },
    connecting: {
        label: "Connexion…",
        dot: "bg-slate-400",
        rail: "border-l-slate-300",
        // Also the state right after a disconnect, which is when someone is watching this
        // line hardest: they just cut the link and need to know a code is coming.
        so: "La passerelle démarre. Un code QR va apparaître ici.",
    },
    logged_out: {
        label: "Déconnectée",
        dot: "bg-red-500",
        rail: "border-l-red-500",
        so: "Le lien a été retiré depuis le téléphone. Les messages attendent.",
    },
    unreachable: {
        label: "Injoignable",
        dot: "bg-red-500",
        rail: "border-l-red-500",
        so: "Le service ne répond pas. Les messages attendent.",
    },
    disabled: {
        label: "Désactivée",
        dot: "bg-slate-400",
        rail: "border-l-slate-300",
        so: null,
    },
};

function StatusChip({ status }) {
    const s = STATUS[status] ?? STATUS.skipped;
    const { Icon } = s;

    return (
        <span
            className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${s.chip}`}
        >
            <Icon className="h-3.5 w-3.5" />
            {s.label}
        </span>
    );
}

/** One label/value pair in the strip's right-hand rail. */
function Metric({ label, value, tone = "text-slate-900" }) {
    return (
        <div className="px-4 first:pl-0 last:pr-0">
            <dt className="text-[11px] uppercase tracking-wide text-slate-400">
                {label}
            </dt>
            <dd className={`text-sm font-semibold tabular-nums ${tone}`}>
                {value}
            </dd>
        </div>
    );
}

/*
 * Connection state and the three numbers that qualify it, on one line.
 *
 * The QR block is the exception to "compact": when a code is showing, it is the only thing
 * on the page that matters, so it gets the room.
 */
function GatewayStrip({ gateway, queue, service, heldCount }) {
    const g = GATEWAY[gateway?.state] ?? GATEWAY.unreachable;
    const connected = gateway?.state === "open";
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    /*
     * Poll whenever the service is not working, not only when a QR is already showing.
     *
     * Watching just `qr` and `logged_out` left a hole exactly where it hurt: disconnecting
     * puts the gateway into `connecting` for a few seconds while it re-establishes and
     * produces a fresh code. Nothing polled that state, so the screen sat on "Connexion…"
     * and the QR never appeared unless somebody reloaded by hand — right after the one
     * action whose entire purpose is to get a new code on screen.
     *
     * `unreachable` is polled for the same reason in reverse: it is how the page notices
     * the service coming back.
     */
    const watching =
        Boolean(gateway?.state) &&
        gateway.state !== "open" &&
        gateway.state !== "disabled";

    useEffect(() => {
        if (!watching) return;
        // A QR expires after about twenty seconds, so this has to be well under that.
        const id = setInterval(() => router.reload({ only: ["gateway"] }), 8000);
        return () => clearInterval(id);
    }, [watching]);

    const disconnect = () => {
        setBusy(true);
        router.post(
            route("notifications.disconnect"),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setConfirming(false);
                    // The gateway needs a moment to tear down and produce a new code.
                    // Without this the strip keeps the pre-click state until the poll
                    // above happens to fire, which reads as "the button did nothing".
                    setTimeout(
                        () => router.reload({ only: ["gateway"] }),
                        1500,
                    );
                },
            },
        );
    };

    return (
        <section
            className={`rounded-xl border border-l-4 border-slate-200 bg-white ${g.rail}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-4 px-5 py-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span
                            className={`h-2 w-2 shrink-0 rounded-full ${g.dot}`}
                        />
                        <h2 className="font-semibold text-slate-900">
                            WhatsApp — {g.label}
                        </h2>
                    </div>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {gateway?.note || g.so}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-x-2 gap-y-3">
                    <dl className="flex divide-x divide-slate-200">
                        <Metric
                            label="Envoyés"
                            value={`${service?.sentToday ?? 0} / ${service?.dailyCap ?? "—"}`}
                        />
                        <Metric
                            label="En file"
                            value={queue?.waiting ?? 0}
                            tone={
                                queue?.waiting > 0
                                    ? "text-sky-700"
                                    : "text-slate-900"
                            }
                        />
                        <Metric
                            label="Mode"
                            value={gateway?.driver ?? "—"}
                        />
                    </dl>

                    {connected && !confirming && (
                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            className="ml-2 inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-red-400"
                        >
                            <Power className="h-4 w-4" />
                            Déconnecter
                        </button>
                    )}
                </div>
            </div>

            {/* Deliberately spells out the cost before the click, not after. */}
            {confirming && (
                <div className="border-t border-red-100 bg-red-50 px-5 py-4">
                    <p className="text-sm font-medium text-red-900">
                        Déconnecter le téléphone de l'école ?
                    </p>
                    <p className="mt-1 text-sm text-red-800">
                        Plus aucun message ne partira tant que quelqu'un n'aura
                        pas scanné un nouveau code avec le téléphone. Les
                        messages en attente seront conservés, pas perdus.
                    </p>
                    <div className="mt-3 flex gap-2">
                        <button
                            type="button"
                            onClick={disconnect}
                            disabled={busy}
                            className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
                        >
                            {busy ? "Déconnexion…" : "Oui, déconnecter"}
                        </button>
                        <button
                            type="button"
                            onClick={() => setConfirming(false)}
                            className="rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm text-red-700 transition hover:bg-red-50"
                        >
                            Annuler
                        </button>
                    </div>
                </div>
            )}

            {gateway?.qr && (
                <div className="flex flex-col items-center gap-5 border-t border-slate-100 bg-slate-50 px-5 py-6 sm:flex-row sm:items-center sm:justify-center">
                    <img
                        src={gateway.qr}
                        alt="Code QR de connexion WhatsApp"
                        width={208}
                        height={208}
                        className="shrink-0 rounded-lg bg-white p-2 shadow-sm"
                    />
                    <ol className="space-y-1.5 text-sm text-slate-600">
                        <li>
                            <b className="text-slate-900">1.</b> Ouvrez WhatsApp
                            sur le téléphone de l'école.
                        </li>
                        <li>
                            <b className="text-slate-900">2.</b> Menu › Appareils
                            connectés.
                        </li>
                        <li>
                            <b className="text-slate-900">3.</b> Connecter un
                            appareil, puis scannez ce code.
                        </li>
                        <li className="pt-1 text-xs text-slate-400">
                            Le code change toutes les 20 secondes. Cette zone se
                            met à jour toute seule.
                        </li>
                    </ol>
                </div>
            )}

            {/* The number that turns "the service is down" into "and here is what it
                is costing you right now". */}
            {heldCount > 0 && !connected && (
                <p className="border-t border-amber-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                    <b>{heldCount}</b> message{heldCount > 1 ? "s" : ""} en
                    attente de la reconnexion. Ils partiront automatiquement.
                </p>
            )}
        </section>
    );
}

/*
 * The settings, folded away.
 *
 * Nobody opens this page to read the pacing interval; they open it to see whether a parent
 * was told. These values matter twice — when the service is first set up, and when someone
 * is diagnosing why sending is slow — so they stay on the page, one click down.
 */
function ServiceDetails({ gateway, queue, service }) {
    const rows = [
        ["Mode d'envoi", gateway?.driver ?? "—"],
        ["Session", gateway?.instance ?? "—"],
        ["Connexion de file", queue?.connection ?? "—"],
        ["Jobs échoués (7 j)", queue?.failedJobs ?? 0],
        [
            "Délai entre 2 envois",
            `${service?.minSeconds ?? "—"} s (+ jusqu'à ${service?.jitter ?? 0} s)`,
        ],
        ["Plafond quotidien", `${service?.dailyCap ?? "—"} par jour`],
        [
            "Heures d'envoi",
            `${service?.from ?? "—"} → ${service?.until ?? "—"}`,
        ],
        ["Abandon après", `${service?.maxAgeDays ?? "—"} jours`],
        [
            "Numéro abandonné après",
            `${service?.maxNumberFailures ?? "—"} échecs`,
        ],
    ];

    return (
        <details className="group rounded-xl border border-slate-200 bg-white">
            <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-400">
                <Settings2 className="h-4 w-4 text-slate-400" />
                Réglages du service
                <span className="font-normal text-slate-400">
                    — ces valeurs protègent le numéro de l'école contre un
                    blocage
                </span>
                <ChevronDown className="ml-auto h-4 w-4 text-slate-400 transition group-open:rotate-180" />
            </summary>

            <dl className="grid gap-x-8 border-t border-slate-100 px-5 py-2 sm:grid-cols-2">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-baseline justify-between gap-4 border-b border-slate-50 py-2.5 last:border-0"
                    >
                        <dt className="text-sm text-slate-500">{label}</dt>
                        <dd className="text-sm font-medium tabular-nums text-slate-900">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

export default function NotificationsPage({
    messages,
    filters,
    counts,
    gateway,
    queue,
    service,
}) {
    const { flash } = usePage().props;
    const [retrying, setRetrying] = useState(null);

    const go = (next) =>
        router.get(
            route("notifications.index"),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const retry = (id) => {
        setRetrying(id);
        router.post(
            route("notifications.retry", id),
            {},
            { preserveScroll: true, onFinish: () => setRetrying(null) },
        );
    };

    const rows = messages?.data ?? [];
    const total = TABS.slice(1).reduce((n, t) => n + (counts[t.key] ?? 0), 0);

    // Minutes the oldest claimable job has gone unclaimed. Positive and growing means no
    // worker is consuming the queue — the one failure here that produces no error anywhere.
    const stalled = queue?.stalledMinutes ?? 0;

    return (
        <div className="m-4 mt-0 flex-1 space-y-5 rounded-md bg-white p-4 md:p-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">
                        Notifications aux parents
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Ce qui a été envoyé, ce qui attend, et ce qui n'est pas
                        parti — avec la raison.
                    </p>
                </div>

                <label className="flex items-center gap-2 text-sm text-slate-600">
                    Journée
                    <input
                        type="date"
                        value={filters.date}
                        onChange={(e) => go({ date: e.target.value })}
                        className="rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900"
                    />
                </label>
            </div>

            {flash?.success && (
                <div className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <GatewayStrip
                gateway={gateway}
                queue={queue}
                service={service}
                heldCount={counts.held ?? 0}
            />

            {/* Says the quiet part out loud. Everything else on this page reports success
                while a stopped worker quietly tells nobody. */}
            {stalled >= 15 && (
                <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                    <div className="text-sm text-red-900">
                        <b>Les envois sont arrêtés.</b> Aucun programme ne
                        traite la file « whatsapp » depuis {stalled} minutes.
                        Les messages sont conservés et partiront dès que le
                        service redémarrera — mais aucun parent n'est prévenu
                        d'ici là.
                    </div>
                </div>
            )}

            {/* Each count is also the filter for that count — the number and the way to
                see the rows behind it are one control, so there is nothing to hunt for. */}
            <div className="flex flex-wrap gap-2">
                {TABS.map((tab) => {
                    const value =
                        tab.key === "all" ? total : (counts[tab.key] ?? 0);
                    const active = filters.status === tab.key;

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => go({ status: tab.key })}
                            aria-pressed={active}
                            className={`flex min-w-[6.75rem] flex-col items-start rounded-lg border px-4 py-2.5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400
                                ${
                                    active
                                        ? `${tab.on} shadow-sm ring-1 ring-inset`
                                        : "border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50"
                                }`}
                        >
                            <span
                                className={`text-xl font-semibold tabular-nums ${
                                    active
                                        ? tab.num
                                        : (STATUS[tab.key]?.tone ??
                                          "text-slate-900")
                                }`}
                            >
                                {value}
                            </span>
                            <span
                                className={`text-xs ${
                                    active
                                        ? `font-medium ${tab.txt}`
                                        : "text-slate-500"
                                }`}
                            >
                                {tab.label}
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr className="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th className="px-4 py-3 font-medium">Élève</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                            <th className="px-4 py-3 font-medium">Détail</th>
                            <th className="px-4 py-3 font-medium">Heure</th>
                            <th className="px-4 py-3 text-right font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-16 text-center text-sm text-slate-500"
                                >
                                    Aucune notification pour cette journée.
                                </td>
                            </tr>
                        )}

                        {rows.map((m) => (
                            <tr key={m.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3">
                                    <Link
                                        href={`/students/${m.student_id}`}
                                        className="text-sm font-medium text-slate-900 hover:underline"
                                    >
                                        {m.studentName}
                                    </Link>
                                    <div className="text-xs text-slate-500">
                                        Absence · {m.channel}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <StatusChip status={m.status} />
                                </td>
                                <td className="px-4 py-3 text-sm text-slate-600">
                                    {m.reason || "—"}
                                    {m.attempts > 1 && (
                                        <span className="ml-1 text-xs text-slate-400">
                                            ({m.attempts} essais)
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-sm tabular-nums text-slate-600">
                                    {m.status === "sent"
                                        ? time(m.sentAt)
                                        : m.status === "pending"
                                          ? `prévu ${time(m.scheduledAt)}`
                                          : m.status === "held"
                                            ? `depuis ${time(m.heldSince ?? m.createdAt)}`
                                            : time(m.createdAt)}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {m.canRetry && (
                                        <button
                                            type="button"
                                            onClick={() => retry(m.id)}
                                            disabled={retrying === m.id}
                                            title="Renvoyer cette notification"
                                            className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-400"
                                        >
                                            <RotateCcw
                                                className={`h-4 w-4 ${retrying === m.id ? "animate-spin" : ""}`}
                                            />
                                            Renvoyer
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Pagination hides itself on a single page, so the count is rendered
                separately — "30 sur 30" is still the answer to "did I see them all?". */}
            {messages?.total > 0 && (
                <div>
                    <Pagination links={messages.links} />
                    <p className="mt-3 text-center text-xs text-slate-500">
                        {messages.from}–{messages.to} sur {messages.total}{" "}
                        notification{messages.total > 1 ? "s" : ""}
                    </p>
                </div>
            )}

            <ServiceDetails
                gateway={gateway}
                queue={queue}
                service={service}
            />
        </div>
    );
}

NotificationsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
