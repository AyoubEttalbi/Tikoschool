import { Link, router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Pagination";
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Inbox,
    MinusCircle,
    PauseCircle,
    Power,
    RotateCcw,
    Smartphone,
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

const TABS = [
    { key: "all", label: "Toutes" },
    { key: "sent", label: "Envoyées" },
    { key: "pending", label: "En file" },
    { key: "held", label: "En pause" },
    { key: "failed", label: "Échecs" },
    { key: "skipped", label: "Non envoyées" },
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
        chip: "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        dot: "bg-emerald-500",
        so: "Les messages partent normalement.",
    },
    qr: {
        label: "À connecter",
        chip: "bg-amber-50 text-amber-700 ring-amber-600/20",
        dot: "bg-amber-500",
        so: "Rien n'est envoyé. Les messages attendent — ils ne sont pas perdus.",
    },
    connecting: {
        label: "Connexion…",
        chip: "bg-slate-100 text-slate-600 ring-slate-500/20",
        dot: "bg-slate-400",
        so: "La passerelle démarre.",
    },
    logged_out: {
        label: "Déconnectée",
        chip: "bg-red-50 text-red-700 ring-red-600/20",
        dot: "bg-red-500",
        so: "Le lien a été retiré depuis le téléphone. Les messages attendent.",
    },
    unreachable: {
        label: "Injoignable",
        chip: "bg-red-50 text-red-700 ring-red-600/20",
        dot: "bg-red-500",
        so: "Le service ne répond pas. Les messages attendent.",
    },
    disabled: {
        label: "Désactivée",
        chip: "bg-slate-100 text-slate-600 ring-slate-500/20",
        dot: "bg-slate-400",
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

function GatewayCard({ gateway, heldCount }) {
    const g = GATEWAY[gateway?.state] ?? GATEWAY.unreachable;
    const connected = gateway?.state === "open";
    const needsScan = gateway?.state === "qr" || gateway?.state === "logged_out";
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    // Poll only while there is something to watch: a QR expires every ~20s. Nothing else
    // on this card changes on its own, so nothing else is worth a request.
    useEffect(() => {
        if (!needsScan) return;
        const id = setInterval(
            () => router.reload({ only: ["gateway"] }),
            12000,
        );
        return () => clearInterval(id);
    }, [needsScan]);

    const disconnect = () => {
        setBusy(true);
        router.post(
            route("notifications.disconnect"),
            {},
            { preserveScroll: true, onFinish: () => { setBusy(false); setConfirming(false); } },
        );
    };

    return (
        <section className="rounded-xl border border-slate-200 bg-white">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div className="flex items-center gap-2.5">
                    <Smartphone className="h-4 w-4 text-slate-400" />
                    <h2 className="font-semibold text-slate-900">
                        Service WhatsApp
                    </h2>
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${g.chip}`}
                    >
                        <span className={`h-1.5 w-1.5 rounded-full ${g.dot}`} />
                        {g.label}
                    </span>
                </div>

                {connected && !confirming && (
                    <button
                        type="button"
                        onClick={() => setConfirming(true)}
                        className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-red-400"
                    >
                        <Power className="h-4 w-4" />
                        Déconnecter
                    </button>
                )}
            </header>

            <div className="px-5 py-4">
                <p className="text-sm text-slate-600">
                    {gateway?.note || g.so}
                </p>

                {/* Deliberately spells out the cost before the click, not after. */}
                {confirming && (
                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
                        <p className="text-sm font-medium text-red-900">
                            Déconnecter le téléphone de l'école ?
                        </p>
                        <p className="mt-1 text-sm text-red-800">
                            Plus aucun message ne partira tant que quelqu'un
                            n'aura pas scanné un nouveau code avec le téléphone.
                            Les messages en attente seront conservés, pas perdus.
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
                    <div className="mt-4 flex flex-col items-center gap-3 rounded-lg bg-slate-50 p-5 sm:flex-row sm:items-start">
                        <img
                            src={gateway.qr}
                            alt="Code QR de connexion WhatsApp"
                            width={200}
                            height={200}
                            className="shrink-0 rounded-lg bg-white p-2 shadow-sm"
                        />
                        <ol className="space-y-1.5 text-sm text-slate-600">
                            <li>
                                <b className="text-slate-900">1.</b> Ouvrez
                                WhatsApp sur le téléphone de l'école.
                            </li>
                            <li>
                                <b className="text-slate-900">2.</b> Menu ›
                                Appareils connectés.
                            </li>
                            <li>
                                <b className="text-slate-900">3.</b> Connecter
                                un appareil, puis scannez ce code.
                            </li>
                            <li className="pt-1 text-xs text-slate-400">
                                Le code change toutes les 20 secondes. Cette
                                zone se met à jour toute seule.
                            </li>
                        </ol>
                    </div>
                )}

                {/* The number that turns "the service is down" into "and here is what it
                    is costing you right now". */}
                {heldCount > 0 && !connected && (
                    <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <b>{heldCount}</b> message{heldCount > 1 ? "s" : ""} en
                        attente de la reconnexion. Ils partiront automatiquement.
                    </p>
                )}
            </div>
        </section>
    );
}

function ServiceFacts({ gateway, queue, service }) {
    const rows = [
        ["Mode d'envoi", gateway?.driver ?? "—"],
        ["Session", gateway?.instance ?? "—"],
        ["File d'attente", `${queue?.waiting ?? 0} en attente`],
        ["Jobs échoués (7 j)", queue?.failedJobs ?? 0],
        ["Connexion de file", queue?.connection ?? "—"],
        ["Délai entre 2 envois", `${service?.minSeconds ?? "—"} s (+ jusqu'à ${service?.jitter ?? 0} s)`],
        ["Plafond quotidien", `${service?.sentToday ?? 0} / ${service?.dailyCap ?? "—"}`],
        ["Heures d'envoi", `${service?.from ?? "—"} → ${service?.until ?? "—"}`],
        ["Abandon après", `${service?.maxAgeDays ?? "—"} jours`],
        ["Numéro abandonné après", `${service?.maxNumberFailures ?? "—"} échecs`],
    ];

    return (
        <section className="rounded-xl border border-slate-200 bg-white">
            <header className="border-b border-slate-100 px-5 py-4">
                <h2 className="font-semibold text-slate-900">
                    Réglages du service
                </h2>
                <p className="mt-0.5 text-xs text-slate-500">
                    Ces valeurs protègent le numéro de l'école contre un
                    blocage.
                </p>
            </header>
            <dl className="divide-y divide-slate-100">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-baseline justify-between gap-4 px-5 py-2.5"
                    >
                        <dt className="text-sm text-slate-500">{label}</dt>
                        <dd className="text-sm font-medium tabular-nums text-slate-900">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            {queue?.waiting > 0 && (
                <p className="m-5 mt-0 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    Si « en attente » ne descend pas, aucun worker ne traite la
                    file « whatsapp ».
                </p>
            )}
        </section>
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

    return (
        <div className="m-4 mt-0 flex-1 space-y-6 rounded-md bg-white p-4 md:p-6">
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

            <div className="grid gap-6 lg:grid-cols-5">
                <div className="lg:col-span-3">
                    <GatewayCard
                        gateway={gateway}
                        heldCount={counts.held ?? 0}
                    />
                </div>
                <div className="lg:col-span-2">
                    <ServiceFacts
                        gateway={gateway}
                        queue={queue}
                        service={service}
                    />
                </div>
            </div>

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
                            className={`flex min-w-[6.5rem] flex-col items-start rounded-lg border px-4 py-2.5 text-left transition
                                ${
                                    active
                                        ? "border-slate-900 bg-slate-900 text-white"
                                        : "border-slate-200 bg-white hover:border-slate-300"
                                }`}
                        >
                            <span
                                className={`text-xl font-semibold tabular-nums ${
                                    active
                                        ? "text-white"
                                        : (STATUS[tab.key]?.tone ??
                                          "text-slate-900")
                                }`}
                            >
                                {value}
                            </span>
                            <span
                                className={`text-xs ${active ? "text-slate-300" : "text-slate-500"}`}
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

            {messages?.links && <Pagination links={messages.links} />}
        </div>
    );
}

NotificationsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
