import { Head, Link, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import SectionCard from "@/Components/Dashboard/SectionCard";
import {
    ArrowRight,
    Bell,
    CalendarClock,
    CalendarDays,
    ClipboardList,
    ReceiptText,
    School,
    UserRound,
    Wallet,
} from "lucide-react";

/*
 * L'espace de travail de l'assistant — pensé comme une FILE DE TRAVAIL, pas un
 * tableau de statistiques : ce qui m'attend, ce qui est urgent, et où agir tout
 * de suite. Les chiffres du haut donnent le volume ; la file donne le plan.
 */

const dh = (value) =>
    `${new Intl.NumberFormat("fr-MA", { minimumFractionDigits: 2 }).format(value ?? 0)} DH`;

const formatDate = (dateString) =>
    dateString
        ? new Date(dateString).toLocaleDateString("fr-FR", {
              day: "numeric",
              month: "short",
              year: "numeric",
          })
        : "—";

const longToday = () =>
    new Date().toLocaleDateString("fr-FR", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
    });

/* Pastilles colorées des indicateurs — même langage visuel que les cartes admin. */
const kpiTones = {
    amber: { bg: "bg-lamaYellow", chip: "bg-white/70 text-amber-600" },
    purple: { bg: "bg-lamaPurple", chip: "bg-white/70 text-purple-700" },
    sky: { bg: "bg-lamaSky", chip: "bg-white/70 text-sky-800" },
    green: { bg: "bg-green-200", chip: "bg-white/70 text-green-700" },
};

function KpiCard({ icon: Icon, label, value, sub, href, tone = "sky", alert }) {
    const classes = kpiTones[tone] ?? kpiTones.sky;

    const body = (
        <div className={`${classes.bg} rounded-2xl p-4 h-full flex flex-col gap-3 transition-shadow group-hover:shadow-lg`}>
            <div className="flex items-center justify-between">
                <span className={`h-9 w-9 rounded-full flex items-center justify-center ${classes.chip}`}>
                    <Icon className="w-5 h-5" aria-hidden="true" />
                </span>
                {alert > 0 && (
                    <span className="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-red-500 text-white text-[11px] font-bold tabular-nums">
                        {alert}
                    </span>
                )}
            </div>
            <div>
                <p className="text-xs font-medium uppercase tracking-wide text-gray-600">
                    {label}
                </p>
                <p className="mt-1 text-2xl xl:text-3xl font-bold text-gray-900 tabular-nums leading-tight">
                    {value}
                </p>
                {sub && <p className="mt-0.5 text-xs text-gray-700">{sub}</p>}
            </div>
        </div>
    );

    if (!href) return body;

    return (
        <Link
            href={href}
            className="block rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-lamaSky focus-visible:ring-offset-2 group"
        >
            {body}
        </Link>
    );
}

/* Tuiles d'accès rapide : une action visible = un geste unique dessus. */
const quickTiles = [
    { label: "Pointer les présences", href: "/attendances", icon: CalendarDays, tone: "hover:border-lamaSky hover:bg-lamaSkyLight/60" },
    { label: "Caisse du jour", href: "/cashier", icon: Wallet, tone: "hover:border-lamaPurple hover:bg-lamaPurpleLight" },
    { label: "Factures impayées", href: "/invoices?status=unpaid", icon: ReceiptText, tone: "hover:border-red-300 hover:bg-red-50" },
    { label: "Mes tâches", href: "/tasks", icon: ClipboardList, tone: "hover:border-green-300 hover:bg-green-50" },
];

function QueueRow({ href, children, right, accent = "border-gray-200" }) {
    const inner = (
        <div className={`flex items-center justify-between gap-3 py-2.5 border-l-4 pl-3 pr-1 -ml-3 rounded-r group-hover:bg-gray-50 transition-colors ${accent}`}>
            <div className="min-w-0">{children}</div>
        </div>
    );

    return (
        <li>
            {href ? (
                <Link href={href} className="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky">
                    <div className="flex items-center justify-between gap-3">
                        {inner}
                        <span className="text-xs font-semibold text-sky-800 shrink-0 flex items-center gap-0.5 tabular-nums">
                            {right ?? "Ouvrir"}
                            <ArrowRight className="w-3.5 h-3.5" aria-hidden="true" />
                        </span>
                    </div>
                </Link>
            ) : (
                <div className="flex items-center justify-between gap-3">
                    {inner}
                    {right && <span className="text-xs font-medium shrink-0">{right}</span>}
                </div>
            )}
        </li>
    );
}

export default function AssistantDashboard({ identity, kpis, queue, announcements = [] }) {
    const { activeSchool, pendingNoticesCount = 0 } = usePage().props;
    const notices = queue?.pendingNotices ?? [];
    const unpaid = queue?.unpaidInvoices ?? [];
    const expiring = queue?.expiringMemberships ?? [];
    const tasks = queue?.openTasks ?? [];

    const handleSchoolChange = () => {
        router.get(route("profiles.select"), { force: 1 }, { preserveState: true });
    };

    return (
        <>
            <Head title="Tableau de bord" />

            <div className="flex-1 p-4 lg:px-8 flex flex-col gap-5">
                {/* En-tête */}
                <header className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">
                            Bonjour {identity.name}
                        </h1>
                        <p className="text-sm text-gray-500 first-letter:uppercase">
                            {longToday()}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        {activeSchool && (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white border border-gray-200 text-xs font-medium text-gray-600">
                                <School className="w-4 h-4 text-lamaSky" aria-hidden="true" />
                                {activeSchool.name}
                            </span>
                        )}
                        {identity.canSwitchSchool && (
                            <button
                                type="button"
                                onClick={handleSchoolChange}
                                className="text-xs px-3 py-1.5 rounded-md bg-lamaSky text-white hover:bg-lamaSky/90 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                            >
                                Changer d'école
                            </button>
                        )}
                        <Link
                            href="/profile"
                            className="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-lamaSkyLight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                        >
                            <UserRound className="w-4 h-4" aria-hidden="true" />
                            Mon profil
                        </Link>
                    </div>
                </header>

                {/* Le volume du jour */}
                <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
                    <KpiCard
                        icon={Bell}
                        label="À valider"
                        value={pendingNoticesCount}
                        sub="avis d'absence"
                        tone="amber"
                        alert={pendingNoticesCount}
                        href="/absence-log"
                    />
                    <KpiCard
                        icon={ReceiptText}
                        label="Impayées"
                        value={kpis.unpaidInvoices.count}
                        sub={`${dh(kpis.unpaidInvoices.totalRest)} à encaisser`}
                        tone="purple"
                        href="/invoices?status=unpaid"
                    />
                    <KpiCard
                        icon={CalendarClock}
                        label="À renouveler"
                        value={kpis.expiringMemberships.count}
                        sub="adhésions sous 7 jours"
                        tone="sky"
                    />
                    <KpiCard
                        icon={Wallet}
                        label="Encaissé"
                        value={dh(kpis.todayCash.total)}
                        sub={`aujourd'hui · hier ${dh(kpis.todayCash.yesterdayTotal)}`}
                        tone="green"
                        href="/cashier"
                    />
                </div>

                {/* File de travail + accès rapide */}
                <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div className="xl:col-span-2 flex flex-col gap-4">
                        <SectionCard
                            icon={Bell}
                            title="À valider maintenant"
                            seeAll="/absence-log"
                            seeAllLabel="Journal des absences"
                        >
                            {notices.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Rien à valider. Tout est parti ou rien n'attend.
                                </p>
                            ) : (
                                <ul className="divide-y divide-dashed divide-gray-100">
                                    {notices.map((notice) => (
                                        <QueueRow
                                            key={notice.id}
                                            href="/absence-log"
                                            accent="border-amber-400"
                                        >
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {notice.student_name}
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                Absence du {formatDate(notice.created_at)} — à vérifier puis envoyer
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard
                            icon={ReceiptText}
                            title="Factures à relancer"
                            seeAll="/invoices?status=unpaid"
                            seeAllLabel={`Voir toutes (${kpis.unpaidInvoices.count})`}
                        >
                            {unpaid.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Aucune facture impayée dans cette école.
                                </p>
                            ) : (
                                <ul className="divide-y divide-dashed divide-gray-100">
                                    {unpaid.map((invoice) => (
                                        <QueueRow
                                            key={invoice.id}
                                            href={`/students/${invoice.student_id}`}
                                            accent="border-purple-400"
                                            right={dh(invoice.rest)}
                                        >
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {invoice.student_name}
                                                {invoice.student_class && (
                                                    <span className="ml-2 text-xs font-normal text-gray-400">
                                                        {invoice.student_class}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                {invoice.offer_name ?? "Offre supprimée"} · échéance{" "}
                                                {formatDate(invoice.billDate)}
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard
                            icon={CalendarClock}
                            title="Adhésions à renouveler"
                        >
                            {expiring.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Aucun renouvellement proche.
                                </p>
                            ) : (
                                <ul className="divide-y divide-dashed divide-gray-100">
                                    {expiring.map((membership) => (
                                        <QueueRow
                                            key={membership.id}
                                            href={`/students/${membership.student_id}`}
                                            accent={
                                                membership.urgency === "expired"
                                                    ? "border-red-500"
                                                    : membership.urgency === "due_soon"
                                                      ? "border-amber-400"
                                                      : "border-sky-400"
                                            }
                                            right={
                                                membership.urgency === "expired"
                                                    ? "Expirée"
                                                    : `J-${membership.days_left}`
                                            }
                                        >
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {membership.student_name}
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                Fin le {formatDate(membership.end_date)}
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard
                            icon={ClipboardList}
                            title="Mes tâches en cours"
                            seeAll="/tasks"
                            seeAllLabel="Voir le tableau"
                        >
                            {tasks.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Aucune tâche ouverte.{" "}
                                    <Link href="/tasks" className="text-sky-700 font-medium hover:underline">Créer la première ?</Link>
                                </p>
                            ) : (
                                <ul className="divide-y divide-dashed divide-gray-100">
                                    {tasks.map((task) => (
                                        <QueueRow
                                            key={task.id}
                                            href="/tasks"
                                            accent={
                                                task.priority === "high"
                                                    ? "border-red-400"
                                                    : "border-green-400"
                                            }
                                            right={
                                                task.due_date
                                                    ? formatDate(task.due_date)
                                                    : task.status === "in_progress"
                                                      ? "En cours"
                                                      : undefined
                                            }
                                        >
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {task.title}
                                                {!task.assigned_to_name && (
                                                    <span className="ml-2 text-xs font-normal text-amber-600">
                                                        non assignée
                                                    </span>
                                                )}
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>

                    {/* Colonne droite : accès rapide + annonces */}
                    <div className="flex flex-col gap-4">
                        <section aria-label="Accès rapide" className="grid grid-cols-2 gap-3">
                            {quickTiles.map((tile) => (
                                <Link
                                    key={tile.label}
                                    href={tile.href}
                                    className={`flex flex-col items-start gap-3 bg-white rounded-xl border border-gray-100 shadow-sm p-4 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky ${tile.tone}`}
                                >
                                    <span className="h-9 w-9 rounded-full bg-lamaSkyLight text-sky-900 flex items-center justify-center">
                                        <tile.icon className="w-5 h-5" aria-hidden="true" />
                                    </span>
                                    <span className="text-sm font-medium text-gray-700 leading-snug">
                                        {tile.label}
                                    </span>
                                </Link>
                            ))}
                        </section>

                        <SectionCard
                            title="Annonces"
                            seeAll="/ViewAllAnnouncements"
                            seeAllLabel="Toutes"
                        >
                            {announcements.length === 0 ? (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Aucune annonce pour le moment.
                                </p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {announcements.map((announcement) => (
                                        <li
                                            key={announcement.id}
                                            className="py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <p className="text-sm font-medium text-gray-800">
                                                {announcement.title}
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                {announcement.content}
                                            </p>
                                            <p className="text-[11px] text-gray-400 mt-0.5">
                                                {formatDate(announcement.date_announcement)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>
                </div>
            </div>
        </>
    );
}

AssistantDashboard.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
