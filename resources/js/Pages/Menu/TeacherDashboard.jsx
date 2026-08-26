import { Head, Link, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import SectionCard from "@/Components/Dashboard/SectionCard";
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    BookOpenCheck,
    CalendarDays,
    GraduationCap,
    Layers,
    School,
    TrendingUp,
    UserRound,
    Wallet,
} from "lucide-react";

/*
 * L'espace de l'enseignant — l'argent d'abord, la pédagogie ensuite.
 *
 * La carte solde porte le delta du mois; le détail des mouvements et des
 * gains par facture vit sur « Ma paie ». Tout ce qui est cliquable mène là où
 * l'action se passe.
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

const kpiTones = {
    amber: { bg: "bg-lamaYellow", chip: "bg-white/70 text-amber-600" },
    purple: { bg: "bg-lamaPurple", chip: "bg-white/70 text-purple-700" },
    sky: { bg: "bg-lamaSky", chip: "bg-white/70 text-sky-800" },
    green: { bg: "bg-green-200", chip: "bg-white/70 text-green-700" },
};

/* Flux du mois : vert quand les commissions dépassent ce qui est reparti
   (paiements/annulations), ambre sinon. Le signe porte le sens, pas la
   comparaison au mois précédent — d'où l'absence de paramètre previous. */
function MonthDelta({ current }) {
    if ((current ?? 0) === 0) return null;
    const up = current > 0;
    return (
        <span
            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold tabular-nums ${
                up ? "bg-white/70 text-green-700" : "bg-white/70 text-amber-700"
            }`}
        >
            {up ? (
                <ArrowUpRight className="w-3 h-3" aria-hidden="true" />
            ) : (
                <ArrowDownLeft className="w-3 h-3" aria-hidden="true" />
            )}
            {dh(current)} ce mois
        </span>
    );
}

/* Tendance nette sur 12 semaines — retirée de la carte solde (produit) :
   l'historique détaillé vit sur « Ma paie ». */

function KpiCard({ icon: Icon, label, value, sub, href, tone = "sky", alert, children }) {
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
            {children}
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

const quickTiles = [
    { label: "Pointer les présences", href: "/attendances", icon: CalendarDays, tone: "hover:border-lamaSky hover:bg-lamaSkyLight/60" },
    { label: "Saisir des notes", href: "/results", icon: BookOpenCheck, tone: "hover:border-lamaPurple hover:bg-lamaPurpleLight" },
    { label: "Mes classes", href: "/classes", icon: Layers, tone: "hover:border-green-300 hover:bg-green-50" },
    { label: "Ma paie", href: "/my-payments", icon: Wallet, tone: "hover:border-lamaYellow hover:bg-lamaYellowLight" },
];

function QueueRow({ href, children, right, accent = "border-gray-200" }) {
    const inner = (
        <div className={`flex items-center justify-between gap-3 py-2.5 border-l-4 pl-3 pr-1 -ml-3 rounded-r group-hover:bg-gray-50 transition-colors ${accent}`}>
            <div className="min-w-0">{children}</div>
        </div>
    );

    if (!href) {
        return (
            <li>
                <div className="flex items-center justify-between gap-3">
                    {inner}
                    {right && <span className="text-xs font-medium shrink-0">{right}</span>}
                </div>
            </li>
        );
    }

    return (
        <li>
            <Link href={href} className="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky">
                <div className="flex items-center justify-between gap-3">
                    {inner}
                    <span className="text-xs font-semibold text-sky-800 shrink-0 flex items-center gap-0.5 tabular-nums">
                        {right ?? "Ouvrir"}
                        <ArrowRight className="w-3.5 h-3.5" aria-hidden="true" />
                    </span>
                </div>
            </Link>
        </li>
    );
}

/* Mouvement de registre : signe porté par la couleur et l'icône, jamais par le texte seul. */
function LedgerRow({ entry }) {
    const credit = entry.amount >= 0;
    const Icon = credit ? ArrowDownLeft : ArrowUpRight;

    return (
        <li className="flex items-center gap-3 py-2 border-b border-gray-100 last:border-b-0">
            <span
                className={`h-8 w-8 shrink-0 rounded-full flex items-center justify-center ${
                    credit ? "bg-green-50 text-green-600" : "bg-red-50 text-red-500"
                }`}
            >
                <Icon className="w-4 h-4" aria-hidden="true" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-gray-800 truncate">{entry.label}</p>
                <p className="text-xs text-gray-400">{formatDate(entry.date)}</p>
            </div>
            <div className="text-right shrink-0">
                <p className={`text-sm font-semibold tabular-nums ${credit ? "text-green-600" : "text-red-500"}`}>
                    {credit ? "+" : "−"}
                    {dh(Math.abs(entry.amount))}
                </p>
                <p className="text-[11px] text-gray-400 tabular-nums">solde {dh(entry.balance_after)}</p>
            </div>
        </li>
    );
}

export default function TeacherDashboard({ identity, kpis = {}, queue = {}, announcements = [] }) {
    const { activeSchool } = usePage().props;
    const wallet = kpis.wallet ?? {};
    const commissions = kpis.pendingCommissions ?? {};
    const ledger = queue.recentLedger ?? [];
    const pendingList = queue.pendingCommissionsList ?? [];
    const tasks = queue.openTasks ?? [];

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
                            Bonjour {identity?.name}
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
                        {identity?.canSwitchSchool && (
                            <button
                                type="button"
                                onClick={handleSchoolChange}
                                className="text-xs px-3 py-1.5 rounded-md bg-lamaSky text-white hover:bg-lamaSky/90 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                            >
                                Changer d'école
                            </button>
                        )}
                        <Link
                            href={identity?.profileUrl ?? "/profile"}
                            className="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-lamaSkyLight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                        >
                            <UserRound className="w-4 h-4" aria-hidden="true" />
                            Mon profil
                        </Link>
                    </div>
                </header>

                {/* L'argent en premier : solde vivant, puis volume pédagogique */}
                <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
                    <KpiCard
                        icon={Wallet}
                        label="Mon solde"
                        value={dh(wallet.balance)}
                        tone="purple"
                        href="/my-payments"
                    >
                        <MonthDelta current={wallet.monthNet} />
                    </KpiCard>
                    <KpiCard
                        icon={TrendingUp}
                        label="À payer ce mois"
                        value={dh(commissions.total)}
                        sub={
                            commissions.count > 0
                                ? `sur ${commissions.count} suivi${commissions.count > 1 ? "s" : ""} de commissions`
                                : "tout est réglé"
                        }
                        tone="amber"
                        href="/my-payments"
                    />
                    <KpiCard
                        icon={GraduationCap}
                        label="Mes élèves"
                        value={kpis.myStudents ?? 0}
                        tone="sky"
                        href="/classes"
                    />
                    <KpiCard
                        icon={Layers}
                        label="Mes classes"
                        value={kpis.myClasses ?? 0}
                        tone="green"
                        href="/classes"
                    />
                </div>

                {/* Actions rapides */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    {quickTiles.map(({ label, href, icon: Icon, tone }) => (
                        <Link
                            key={label}
                            href={href}
                            className={`flex items-center gap-3 rounded-xl bg-white border border-gray-100 shadow-sm p-3 text-sm font-medium text-gray-700 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-lamaSky ${tone}`}
                        >
                            <Icon className="w-5 h-5 text-gray-400" aria-hidden="true" />
                            {label}
                        </Link>
                    ))}
                </div>

                {/* File : mouvements + commissions + tâches */}
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <SectionCard title="Derniers mouvements" seeAll="/my-payments" seeAllLabel="Ma paie complète">
                        {ledger.length === 0 ? (
                            <p className="text-sm text-gray-400 py-4 text-center">
                                Aucun mouvement pour l'instant — vos commissions apparaîtront ici.
                            </p>
                        ) : (
                            <ul className="divide-y divide-transparent">
                                {ledger.map((entry) => (
                                    <LedgerRow key={entry.id} entry={entry} />
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    <div className="flex flex-col gap-4">
                        <SectionCard title="Commissions en attente" seeAll="/my-payments" seeAllLabel="Détail">
                            {(pendingList.length === 0 && (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Rien en attente — toutes vos commissions sont payées.
                                </p>
                            )) || (
                                <ul>
                                    {pendingList.map((row, i) => (
                                        <QueueRow key={`${row.student_name}-${i}`} accent="border-lamaYellow">
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {row.student_name ?? "Élève"}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {row.months_count} mois à régler
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard title="Mes tâches" seeAll="/tasks" seeAllLabel="Voir le tableau">
                            {(tasks.length === 0 && (
                                <p className="text-sm text-gray-400 py-4 text-center">
                                    Aucune tâche ouverte qui vous est assignée.
                                </p>
                            )) || (
                                <ul>
                                    {tasks.map((task) => (
                                        <QueueRow
                                            key={task.id}
                                            href={`/tasks`}
                                            accent={
                                                task.priority === "high"
                                                    ? "border-red-300"
                                                    : "border-gray-200"
                                            }
                                        >
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {task.title}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {task.due_date ? `Pour le ${formatDate(task.due_date)}` : "Sans échéance"}
                                            </p>
                                        </QueueRow>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>
                </div>

                {/* Annonces */}
                {announcements.length > 0 && (
                    <SectionCard title="Annonces">
                        <ul className="space-y-2">
                            {announcements.map((announcement) => (
                                <li
                                    key={announcement.id}
                                    className="rounded-lg bg-white border border-gray-100 shadow-sm p-3"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="text-sm font-semibold text-gray-800">
                                            {announcement.title}
                                        </p>
                                        <span className="text-[11px] text-gray-400 shrink-0">
                                            {formatDate(announcement.date_announcement)}
                                        </span>
                                    </div>
                                    {announcement.content && (
                                        <p className="mt-1 text-xs text-gray-500 line-clamp-2">
                                            {announcement.content}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}
            </div>
        </>
    );
}

TeacherDashboard.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
