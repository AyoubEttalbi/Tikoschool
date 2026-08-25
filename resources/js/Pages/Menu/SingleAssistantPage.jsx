import React, { useState, useEffect, Suspense } from "react";
import Announcements from "@/Pages/Menu/Announcements/Announcements";
// BigCalendar was imported here but never rendered anywhere in this file — a dead import
// that still pulled react-big-calendar + moment (~202 KB raw / 64 KB gzip) into this
// page's chunk. SingleTeacherPage, which does render it, lazy-loads it.
import FormModal from "@/Components/FormModal";
import DashboardLayout from "@/Layouts/DashboardLayout";
import ProfileImageLightbox from "@/Components/ProfileImageLightbox";
import { Link, usePage, router } from "@inertiajs/react";
import Pagination from "@/Components/Pagination";
import ActivityLogs from "@/Components/ActivityLogs";
import InvoiceModal from "@/Components/InvoiceModal";
import AssistantProfile from "@/Components/AssistantProfile";
import {
    AlertCircle,
    AlertTriangle,
    Calendar,
    ChevronRight,
    Clock,
    DollarSign,
    Eye,
    FileText,
    History,
    User,
} from "lucide-react";
import EventCalendar from "@/Components/EventCalendar";
import axios from "axios";

const SingleAssistantPage = ({
    assistant,
    announcements = [],
    classes,
    subjects,
    schools: initialSchools,
    logs,
    recentAbsences = [],
    unpaidInvoices = [],
    expiringMemberships = [],
    recentPayments = [],
    recentPaymentsLinks = [],
    totalAbsences = 0,
    totalUnpaidInvoices = 0,
    totalExpiringMemberships = 0,
    totalRecentPayments = 0,
    selectedSchool = null,
    statistics = {
        students_count: 0,
        classes_count: 0,
    },
    transactions,
    unpaidInvoicesLinks = [],
    expiringMembershipsLinks = [],
    journal = null,
}) => {
    const role = usePage().props.auth.user.role;

    // Ensure logs has a default value with data array
    const safeLogs = logs || { data: [], links: [] };

    // Admins inspecting this profile get the assistant's own track record —
    // what they created — instead of the school's finance widgets.
    const isAdminJournal = role === "admin" && Boolean(journal?.categories);
    const [activeTab, setActiveTab] = useState(
        role === "admin" ? "invoices" : "absences",
    );
    // Clamp: Inertia keeps component state across visits to the same page class,
    // so a tab picked in journal mode can survive into widget mode (and an
    // admin whose target has no users row gets widgets + no journal). Without
    // this both cases render an empty white card.
    const validTabs = isAdminJournal
        ? journalTabs.map((tab) => tab.key)
        : ["absences", "unpaid", "memberships", "payments"];
    const currentTab = validTabs.includes(activeTab)
        ? activeTab
        : isAdminJournal
          ? "invoices"
          : "absences";

    // State for invoice modal
    const [isInvoiceModalOpen, setIsInvoiceModalOpen] = useState(false);
    const [selectedInvoice, setSelectedInvoice] = useState(null);
    const [isInvoiceLoading, setIsInvoiceLoading] = useState(false);
    const [invoiceLoadError, setInvoiceLoadError] = useState(false);

    // State to ensure schools list is always available for the update form
    const [schools, setSchools] = useState(initialSchools || []);

    const renderCount = (count) => (
        <span
            className={`${count === 0 ? "text-green-600" : "text-red-600"} ml-1`}
        >
            ({count})
        </span>
    );

    // Function to open invoice modal
    const openInvoiceModal = (invoice) => {
        setIsInvoiceLoading(true);
        setInvoiceLoadError(false);
        // Fetch full invoice details from backend API
        axios
            .get(`/api/invoices/${invoice.id}`)
            .then((response) => {
                setSelectedInvoice(response.data.invoice);
                setIsInvoiceModalOpen(true);
            })
            .catch(() => {
                // Without this the spinner just cleared and nothing happened —
                // the user got no signal at all that the details never arrived.
                setInvoiceLoadError(true);
            })
            .finally(() => setIsInvoiceLoading(false));
    };

    // Function to close invoice modal
    const closeInvoiceModal = () => {
        setIsInvoiceModalOpen(false);
        // Clear selected invoice after animation completes
        setTimeout(() => setSelectedInvoice(null), 300);
    };

    // Helper to determine if we need a "See more" button
    const hasMoreItems = (current, total) => {
        return total > current.length;
    };

    // Handle school change
    const handleSchoolChange = () => {
        // Only trigger if the assistant has multiple schools
        if (assistant?.schools?.length > 1) {
            router.get(
                route("profiles.select"),
                { force: 1 },
                {
                    preserveState: true,
                    preserveScroll: true,
                },
            );
        }
    };

    useEffect(() => {
        if (!schools || schools.length === 0) {
            // Fetch schools if not provided (fallback). Was "/api/schools" — a
            // route that never existed, so this silently 404'd into the
            // fallback redirect on every load.
            fetch("/schoolsForFilters")
                .then((res) => (res.ok ? res.json() : []))
                .then((data) => {
                    if (Array.isArray(data)) setSchools(data);
                })
                .catch(() => {});
        }
    }, [schools]);

    return (
        <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
            {/* GAUCHE */}
            <div className="w-full lg:px-10">
                {/* Bannière de sélection d'école */}
                {selectedSchool &&
                    assistant.schools &&
                    assistant.schools.length > 1 && (
                        <div className="w-full mb-4 p-3 bg-lamaSkyLight border border-lamaSky/20 rounded-md flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <div className="h-8 w-8 bg-white rounded-full flex items-center justify-center">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        className="h-5 w-5 text-lamaSky"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838l-2.727 1.666 1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z" />
                                    </svg>
                                </div>
                                <div>
                                    <div className="text-xs text-gray-600">
                                        École actuelle
                                    </div>
                                    <span className="font-medium">
                                        {selectedSchool.name}
                                    </span>
                                </div>
                            </div>
                            <button
                                onClick={handleSchoolChange}
                                className="text-sm px-3 py-1 flex items-center gap-1 bg-lamaSky text-white rounded-md hover:bg-lamaSky/90 transition-colors"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    className="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth={2}
                                        d="M8 7h12m0 0l-4-4m4 4l-4 4m-4 6H4m0 0l4 4m-4-4l4-4"
                                    />
                                </svg>
                                Changer d'école
                            </button>
                        </div>
                    )}

                {/* TOP */}
                <div className="flex flex-col lg:flex-row gap-4">
                    {/* CARTE INFO UTILISATEUR */}
                    <div className="bg-lamaSky py-6 px-4 rounded-md flex-1 flex gap-4">
                        <div className="w-1/3">
                            <ProfileImageLightbox
                                src={
                                    assistant.profile_image ||
                                    // "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200"
                                    "/assistantProfile.png"
                                }
                                alt={assistant.last_name}
                                enabled={Boolean(assistant.profile_image)}
                                className="w-36 h-36 rounded-full object-cover"
                            />
                        </div>
                        <div className="w-2/3 flex flex-col justify-between gap-4">
                            <div className="flex items-center gap-4">
                                <h1 className="text-xl font-semibold">
                                    {assistant.first_name} {assistant.last_name}
                                </h1>
                                <span
                                    className={`px-3 py-1 rounded-full text-xs font-medium ${
                                        assistant.status === "inactive"
                                            ? "bg-red-100 text-red-800 border border-red-200"
                                            : "bg-green-100 text-green-800 border border-green-200"
                                    }`}
                                >
                                    {assistant.status === "inactive"
                                        ? "Inactif"
                                        : "Actif"}
                                </span>
                            </div>
                            <p className="text-sm text-gray-500">
                                {assistant.bio ||
                                    "Aucune biographie disponible."}
                            </p>
                            <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                                    <img
                                        src="/school.png"
                                        alt="École"
                                        width={14}
                                        height={14}
                                    />
                                    <span>
                                        {assistant.schools &&
                                        assistant.schools.length > 0
                                            ? assistant.schools
                                                  .map((school) => school.name)
                                                  .join(", ")
                                            : "N/A"}
                                    </span>
                                </div>
                                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                                    <img
                                        src="/date.png"
                                        alt="Date"
                                        width={14}
                                        height={14}
                                    />
                                    <span>
                                        {assistant.created_at
                                            ? new Intl.DateTimeFormat("fr-FR", {
                                                  month: "long",
                                                  year: "numeric",
                                              }).format(
                                                  new Date(
                                                      assistant.created_at,
                                                  ),
                                              )
                                            : "N/A"}
                                    </span>
                                </div>
                                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                                    <img
                                        src="/mail.png"
                                        alt="E-mail"
                                        width={14}
                                        height={14}
                                    />
                                    <span>{assistant.email}</span>
                                </div>
                                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                                    <img
                                        src="/phone.png"
                                        alt="Téléphone"
                                        width={14}
                                        height={14}
                                    />
                                    <span>{assistant.phone_number}</span>
                                </div>
                            </div>
                        </div>
                        {role === "admin" && (
                            <FormModal
                                table="assistant"
                                type="update"
                                data={{
                                    ...assistant,
                                    schools_assistant:
                                        assistant.schools?.map(
                                            ({ id, name }) => ({ id, name }),
                                        ) || [],
                                }}
                                schools={schools}
                                groups={classes}
                                subjects={subjects}
                                icon={"updateIcon2"}
                                selectedSchool={selectedSchool}
                            />
                        )}
                    </div>

                    {/* SMALL CARDS */}
                    <div className="flex-1 flex gap-4 justify-between flex-wrap">
                        <InfoCard
                            icon="/singleAttendance.png"
                            label="Élèves"
                            value={statistics.students_count}
                        />
                        <InfoCard
                            icon="/singleBranch.png"
                            label="École"
                            value={selectedSchool ? selectedSchool.name : "N/A"}
                        />
                        <InfoCard
                            icon="/singleLesson.png"
                            label="Classes"
                            value={statistics.classes_count}
                        />
                        <InfoCard
                            icon={
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="24"
                                    height="24"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    className="text-lamaSky"
                                >
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <polyline points="16 11 18 13 22 9"></polyline>
                                </svg>
                            }
                            label="Statut"
                            value={
                                <span
                                    className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                                        assistant.status === "inactive"
                                            ? "bg-red-100 text-red-800"
                                            : "bg-green-100 text-green-800"
                                    }`}
                                >
                                    {assistant.status === "inactive"
                                        ? "Inactif"
                                        : "Actif"}
                                </span>
                            }
                        />
                    </div>
                </div>

                {/* FEATURE TABS — admins inspecting this profile see the assistant's
                    own track record (rich per-category journal + performance strip
                    + full history tab) instead of the school's finance widgets.
                    The old standalone « Journaux d'activité » section is gone: the
                    history tab IS that log, so there is exactly one log surface. */}
                {isAdminJournal ? (
                    <ActivityJournal
                        journal={journal}
                        logs={safeLogs}
                        activeTab={currentTab}
                        setActiveTab={setActiveTab}
                        onOpenInvoice={openInvoiceModal}
                    />
                ) : (
                    <div className="mt-4 bg-white rounded-md p-4">
                        <div className="border-b border-gray-200 mb-4">
                            <nav className="-mb-px flex overflow-x-auto pb-1">
                                <TabButton
                                    onClick={() => setActiveTab("absences")}
                                    isActive={currentTab === "absences"}
                                    icon={
                                        <AlertCircle className="w-4 h-4 mr-1" />
                                    }
                                    text={
                                        <>
                                            <span>Absences récentes</span>{" "}
                                            {renderCount(totalAbsences)}
                                        </>
                                    }
                                />
                                <TabButton
                                    onClick={() => setActiveTab("unpaid")}
                                    isActive={currentTab === "unpaid"}
                                    icon={<FileText className="w-4 h-4 mr-1" />}
                                    text={
                                        <>
                                            <span>Factures impayées</span>{" "}
                                            {renderCount(totalUnpaidInvoices)}
                                        </>
                                    }
                                />
                                <TabButton
                                    onClick={() => setActiveTab("memberships")}
                                    isActive={currentTab === "memberships"}
                                    icon={<Calendar className="w-4 h-4 mr-1" />}
                                    text={
                                        <>
                                            <span>
                                                Adhésions expirant bientôt
                                            </span>{" "}
                                            {renderCount(
                                                totalExpiringMemberships,
                                            )}
                                        </>
                                    }
                                />
                                <TabButton
                                    onClick={() => setActiveTab("payments")}
                                    isActive={currentTab === "payments"}
                                    icon={
                                        <DollarSign className="w-4 h-4 mr-1" />
                                    }
                                    text={
                                        <>
                                            <span>Paiements récents</span>{" "}
                                            {renderCount(totalRecentPayments)}
                                        </>
                                    }
                                />
                            </nav>
                        </div>

                        {/* Contenu des onglets */}
                        <div className="py-2">
                            {currentTab === "absences" && (
                                <>
                                    <DataTable
                                        data={recentAbsences}
                                        columns={[
                                            {
                                                header: "Élève",
                                                accessor: (item) => (
                                                    <Link
                                                        href={`/students/${item.student_id}`}
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        {item.student_name}
                                                    </Link>
                                                ),
                                            },
                                            {
                                                header: "Classe",
                                                accessor: "class_name",
                                            },
                                            {
                                                header: "Date",
                                                accessor: (item) =>
                                                    formatDate(item.date),
                                            },
                                            {
                                                header: "Statut",
                                                accessor: (item) => (
                                                    <span
                                                        className={`px-2 py-1 rounded-full text-xs ${item.status === "absent" ? "bg-red-100 text-red-800" : "bg-yellow-100 text-yellow-800"}`}
                                                    >
                                                        {item.status ===
                                                        "absent"
                                                            ? "Absent"
                                                            : "Retard"}
                                                    </span>
                                                ),
                                            },
                                            {
                                                header: "Raison",
                                                accessor: "reason",
                                            },
                                        ]}
                                        emptyMessage="Aucune absence récente durant les 30 derniers jours"
                                    />
                                    {hasMoreItems(
                                        recentAbsences,
                                        totalAbsences,
                                    ) && (
                                        <div className="mt-4 text-center">
                                            <Link
                                                href={`/attendances?filter=recent&school=${selectedSchool ? selectedSchool.id : ""}`}
                                                className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                                            >
                                                Voir toutes les absences (
                                                {totalAbsences}) des 7 derniers
                                                jours
                                                <ChevronRight className="ml-1 w-4 h-4" />
                                            </Link>
                                        </div>
                                    )}
                                </>
                            )}

                            {currentTab === "unpaid" && (
                                <>
                                    <DataTable
                                        data={unpaidInvoices}
                                        columns={[
                                            {
                                                header: "Élève",
                                                accessor: (item) => (
                                                    <Link
                                                        href={`/students/${item.student_id}`}
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        {item.student_name}
                                                    </Link>
                                                ),
                                            },
                                            {
                                                header: "Date de facture",
                                                accessor: (item) =>
                                                    formatDate(item.billDate),
                                            },
                                            {
                                                header: "Total",
                                                accessor: (item) =>
                                                    `${item.totalAmount} DH`,
                                            },
                                            {
                                                header: "Restant",
                                                accessor: (item) => (
                                                    <span className="font-semibold text-red-600">
                                                        {item.rest} DH
                                                    </span>
                                                ),
                                            },
                                            {
                                                header: "Actions",
                                                accessor: (item) => (
                                                    <button
                                                        onClick={() =>
                                                            openInvoiceModal(
                                                                item,
                                                            )
                                                        }
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>
                                                ),
                                            },
                                        ]}
                                        emptyMessage="Aucune facture impayée trouvée"
                                    />
                                    {unpaidInvoicesLinks &&
                                        unpaidInvoicesLinks.length > 0 && (
                                            <div className="mt-4">
                                                <Pagination
                                                    links={unpaidInvoicesLinks}
                                                />
                                            </div>
                                        )}
                                    {hasMoreItems(
                                        unpaidInvoices,
                                        totalUnpaidInvoices,
                                    ) && (
                                        <div className="mt-4 text-center">
                                            <Link
                                                href={`/invoices?status=unpaid${selectedSchool ? `&school=${selectedSchool.id}` : ""}`}
                                                className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                                            >
                                                Voir toutes les factures
                                                impayées ({totalUnpaidInvoices})
                                                <ChevronRight className="ml-1 w-4 h-4" />
                                            </Link>
                                        </div>
                                    )}
                                </>
                            )}

                            {currentTab === "memberships" && (
                                <>
                                    <DataTable
                                        data={expiringMemberships}
                                        columns={[
                                            {
                                                header: "Élève",
                                                accessor: (item) => (
                                                    <Link
                                                        href={`/students/${item.student_id}`}
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        {item.student_name}
                                                    </Link>
                                                ),
                                            },
                                            {
                                                header: "Début",
                                                accessor: (item) =>
                                                    formatDate(item.start_date),
                                            },
                                            {
                                                header: "Expire",
                                                accessor: (item) =>
                                                    formatDate(item.end_date),
                                            },
                                            {
                                                header: "Jours restants",
                                                accessor: (item) => (
                                                    <span className="font-semibold text-amber-600">
                                                        {item.days_left}
                                                    </span>
                                                ),
                                            },
                                            {
                                                header: "Actions",
                                                accessor: (item) => (
                                                    <Link
                                                        href={`/students/${item.student_id}`}
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </Link>
                                                ),
                                            },
                                        ]}
                                        emptyMessage="Aucune adhésion expirant bientôt"
                                    />
                                    {expiringMembershipsLinks &&
                                        expiringMembershipsLinks.length > 0 && (
                                            <div className="mt-4">
                                                <Pagination
                                                    links={
                                                        expiringMembershipsLinks
                                                    }
                                                />
                                            </div>
                                        )}
                                    {/* NOTE: pas de lien « Voir toutes » ici : GET /memberships
                                    n'existe pas (ressource except index) et le lien pointait
                                    vers Route::fallback, qui rebondissait sur cette page. */}
                                </>
                            )}

                            {currentTab === "payments" && (
                                <>
                                    <DataTable
                                        data={recentPayments}
                                        columns={[
                                            {
                                                header: "Élève",
                                                accessor: (item) => (
                                                    <Link
                                                        href={`/students/${item.student_id}`}
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        {item.student_name}
                                                    </Link>
                                                ),
                                            },
                                            {
                                                header: "Date",
                                                accessor: (item) =>
                                                    formatDate(
                                                        item.payment_date,
                                                    ),
                                            },
                                            {
                                                header: "Montant",
                                                accessor: (item) => (
                                                    <span className="font-semibold text-green-600">
                                                        {item.amount} DH
                                                    </span>
                                                ),
                                            },
                                            {
                                                header: "Méthode",
                                                accessor: "payment_method",
                                            },
                                            {
                                                header: "Offre",
                                                accessor: "offer_name",
                                            },
                                            {
                                                header: "Actions",
                                                accessor: (item) => (
                                                    <button
                                                        onClick={() =>
                                                            openInvoiceModal(
                                                                item,
                                                            )
                                                        }
                                                        className="text-blue-600 hover:text-blue-800"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>
                                                ),
                                            },
                                        ]}
                                        emptyMessage="Aucun paiement récent trouvé"
                                    />
                                    {recentPaymentsLinks &&
                                        recentPaymentsLinks.length > 0 && (
                                            <div className="mt-4">
                                                <Pagination
                                                    links={recentPaymentsLinks}
                                                />
                                            </div>
                                        )}
                                    {hasMoreItems(
                                        recentPayments,
                                        totalRecentPayments,
                                    ) && (
                                        <div className="mt-4 text-center">
                                            <Link
                                                href={`/assistants/${assistant.id}/student-payments`}
                                                className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                                            >
                                                Voir tous les paiements des
                                                étudiants ({totalRecentPayments}
                                                )
                                                <ChevronRight className="ml-1 w-4 h-4" />
                                            </Link>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* Assistant Profile — « Historique des paiements » : volontairement
                      en dernier, sous le journal d'activité. */}
                <AssistantProfile
                    assistant={assistant}
                    transactions={transactions}
                />
            </div>

            {/* DROITE */}
            {/* <div className="w-full xl:w-1/3 flex flex-col gap-4">
                <div className="bg-white p-4 rounded-md">
                    
                    <Suspense fallback={<div>Chargement...</div>}>
                        <EventCalendar />
                    </Suspense>
                </div>
                <Announcements announcements={announcements} userRole={role} />
            </div> */}

            {/* Modal de facture */}
            <InvoiceModal
                isOpen={isInvoiceModalOpen}
                closeModal={closeInvoiceModal}
                invoice={selectedInvoice}
            />
            {isInvoiceLoading && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
                    <div className="bg-white p-6 rounded shadow text-lg font-semibold">
                        Chargement de la facture...
                    </div>
                </div>
            )}
            {invoiceLoadError && (
                <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow">
                    <span>Impossible de charger la facture. Veuillez réessayer.</span>
                    <button
                        type="button"
                        onClick={() => setInvoiceLoadError(false)}
                        className="rounded px-2 py-1 font-semibold text-red-700 hover:bg-red-100"
                    >
                        Fermer
                    </button>
                </div>
            )}
        </div>
    );
};

// Helper to format dates
const formatDate = (dateString) => {
    if (!dateString) return "N/A";
    try {
        let date;
        // Handle date-only strings (YYYY-MM-DD) without timezone conversion
        if (
            typeof dateString === "string" &&
            /^\d{4}-\d{2}-\d{2}$/.test(dateString)
        ) {
            // Parse as local date to avoid timezone shifts
            const [year, month, day] = dateString.split("-").map(Number);
            date = new Date(year, month - 1, day);
        } else {
            // DateTime string: parse normally
            date = new Date(dateString);
        }
        return date.toLocaleDateString("fr-FR", {
            year: "numeric",
            month: "short",
            day: "numeric",
        });
    } catch (error) {
        return "N/A";
    }
};

// Tab Button Component
const TabButton = ({ onClick, isActive, icon, text }) => (
    <button
        onClick={onClick}
        className={`flex items-center px-4 py-2 text-sm font-medium whitespace-nowrap ${
            isActive
                ? "border-b-2 border-blue-500 text-blue-600"
                : "text-gray-500 hover:text-gray-700 hover:border-gray-300"
        }`}
    >
        {icon}
        {text}
    </button>
);

// Generic Data Table Component
const DataTable = ({ data, columns, emptyMessage }) => (
    <div className="rounded-lg border border-gray-100 overflow-hidden">
        {data.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
                <AlertTriangle className="w-8 h-8 mx-auto mb-2 text-gray-400" />
                <p>{emptyMessage || "Aucune donnée disponible"}</p>
            </div>
        ) : (
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            {columns.map((col, idx) => (
                                <th
                                    key={idx}
                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    {col.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {data.map((item, idx) => (
                            <tr key={idx} className="hover:bg-gray-50">
                                {columns.map((col, colIdx) => (
                                    <td
                                        key={colIdx}
                                        className="px-6 py-4 whitespace-nowrap text-sm text-gray-500"
                                    >
                                        {typeof col.accessor === "function"
                                            ? col.accessor(item)
                                            : item[col.accessor] || "N/A"}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        )}
    </div>
);

const InfoCard = ({ icon, label, value }) => (
    <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
        {typeof icon === "string" ? (
            <img src={icon} alt="" width={24} height={24} className="w-6 h-6" />
        ) : (
            icon
        )}
        <div>
            <div className="text-xl font-semibold">{value}</div>
            <span className="text-sm text-gray-400">{label}</span>
        </div>
    </div>
);

/*
 * Le journal d'activité d'un assistant, vu par un admin : ce que CETTE personne a
 * créé (factures, élèves, absences enregistrées, adhésions), lu depuis la table
 * activity_log partagée — avec le contexte métier de chaque création (élève, offre,
 * montants…), pas seulement un identifiant. Un dernier onglet « Historique complet »
 * reprend le journal général (mises à jour, suppressions) : c'est le même flux,
 * un seul composant de logs sur la page.
 *
 * Au-dessus des onglets, une barre de performance répond à « cette personne est-elle
 * active ? » : créations sur 7 jours / 30 jours / total, tendance sur six semaines
 * (barres CSS, zéro dépendance) et date de la dernière action quelconque.
 */
const journalTabs = [
    { key: "invoices", label: "Factures créées", icon: FileText },
    { key: "students", label: "Élèves créés", icon: User },
    { key: "absences", label: "Absences enregistrées", icon: AlertCircle },
    { key: "memberships", label: "Adhésions créées", icon: Calendar },
    { key: "history", label: "Historique complet", icon: History },
];

const journalEntryLabel = (key, entry) => {
    const ref =
        entry.description?.match(/\((\d+)\)/)?.[1] ?? entry.subject_id ?? "—";
    if (key === "invoices") return `Facture #${ref}`;
    if (key === "students") return `Élève #${ref}`;
    if (key === "absences") return `Absence enregistrée (#${ref})`;
    return `Adhésion #${ref}`;
};

const formatDateTime = (iso) => {
    if (!iso) return "—";
    try {
        return new Date(iso).toLocaleString("fr-FR", {
            day: "numeric",
            month: "short",
            hour: "2-digit",
            minute: "2-digit",
        });
    } catch {
        return "—";
    }
};

const relativeDays = (iso) => {
    if (!iso) return "Jamais";
    try {
        const days = Math.floor((Date.now() - new Date(iso)) / 86400000);
        if (days <= 0) return "Aujourd'hui";
        if (days === 1) return "Hier";
        if (days < 30) return `Il y a ${days} jours`;
        const months = Math.floor(days / 30);
        return `Il y a ${months} mois`;
    } catch {
        return "—";
    }
};

const PerformanceStrip = ({ performance }) => {
    if (!performance) return null;
    const max = Math.max(1, ...performance.weekly.map((w) => w.count));

    return (
        <div className="mb-4 p-4 rounded-lg bg-gray-50 border border-gray-100 flex flex-col lg:flex-row lg:items-center gap-5">
            <div className="flex gap-3 flex-wrap">
                {[
                    { label: "7 derniers jours", value: performance.last7 },
                    { label: "30 derniers jours", value: performance.last30 },
                    { label: "Total", value: performance.total },
                ].map((chip) => (
                    <div
                        key={chip.label}
                        className="px-3 py-2 rounded-md bg-white border border-gray-150 min-w-[110px]"
                    >
                        <div className="text-lg font-semibold text-gray-800 tabular-nums">
                            {chip.value}
                        </div>
                        <div className="text-[11px] text-gray-400">
                            {chip.label}
                        </div>
                    </div>
                ))}
                <div className="px-3 py-2 rounded-md bg-white border border-gray-150 min-w-[130px]">
                    <div className="text-sm font-medium text-gray-700">
                        {relativeDays(performance.last_activity_at)}
                    </div>
                    <div className="text-[11px] text-gray-400">
                        Dernière action
                    </div>
                </div>
            </div>

            <div className="flex-1">
                <div className="text-[11px] text-gray-400 mb-1.5">
                    Créations par semaine (6 dernières semaines)
                </div>
                <div className="flex items-end gap-1.5 h-12" aria-hidden="true">
                    {performance.weekly.map((week) => (
                        <div
                            key={week.label}
                            className="flex flex-col items-center gap-1 flex-1"
                        >
                            <div
                                title={`${week.count} création(s) — semaine du ${week.label}`}
                                className={`w-full rounded-t transition-all ${
                                    week.count > 0
                                        ? "bg-lamaSky"
                                        : "bg-gray-200"
                                }`}
                                style={{
                                    height: `${Math.max(8, (week.count / max) * 40)}px`,
                                }}
                            />
                            <span className="text-[9px] text-gray-400 tabular-nums">
                                {week.label}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

const ActivityJournal = ({
    journal,
    logs,
    activeTab,
    setActiveTab,
    onOpenInvoice,
}) => {
    const category = journal.categories[activeTab] ?? {
        total: 0,
        entries: [],
    };

    const money = (n) => `${n} DH`;
    const statusBadge = (status) => (
        <span
            className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                status === "absent"
                    ? "bg-red-100 text-red-800"
                    : "bg-yellow-100 text-yellow-800"
            }`}
        >
            {status === "absent" ? "Absent" : "Retard"}
        </span>
    );

    const detailColumns = {
        invoices: [
            {
                header: "Élève",
                accessor: (e) => e.detail?.student_name ?? "—",
            },
            { header: "Offre", accessor: (e) => e.detail?.offer_name ?? "—" },
            {
                header: "Total",
                accessor: (e) => (e.detail ? money(e.detail.total) : "—"),
            },
            {
                header: "Payé",
                accessor: (e) => (
                    <span className="font-medium text-green-700">
                        {e.detail ? money(e.detail.paid) : "—"}
                    </span>
                ),
            },
            {
                header: "Restant",
                accessor: (e) => (
                    <span
                        className={`font-medium ${
                            (e.detail?.rest ?? 0) > 0
                                ? "text-red-600"
                                : "text-gray-400"
                        }`}
                    >
                        {e.detail ? money(e.detail.rest) : "—"}
                    </span>
                ),
            },
            {
                header: "Créée le",
                accessor: (e) => formatDateTime(e.created_at),
            },
            {
                header: "Actions",
                accessor: (e) => (
                    <button
                        onClick={() => onOpenInvoice({ id: e.subject_id })}
                        aria-label="Voir la facture"
                        className="text-blue-600 hover:text-blue-800"
                    >
                        <Eye className="w-4 h-4" />
                    </button>
                ),
            },
        ],
        students: [
            {
                header: "Élève",
                accessor: (e) =>
                    e.detail?.student_name ?? journalEntryLabel("students", e),
            },
            { header: "Classe", accessor: (e) => e.detail?.class_name ?? "—" },
            {
                header: "Créé le",
                accessor: (e) => formatDateTime(e.created_at),
            },
            {
                header: "Actions",
                accessor: (e) => (
                    <Link
                        href={`/students/${e.subject_id}`}
                        aria-label="Voir l'élève"
                        className="text-blue-600 hover:text-blue-800"
                    >
                        <Eye className="w-4 h-4" />
                    </Link>
                ),
            },
        ],
        absences: [
            {
                header: "Élève",
                accessor: (e) => e.detail?.student_name ?? "—",
            },
            { header: "Classe", accessor: (e) => e.detail?.class_name ?? "—" },
            {
                header: "Statut",
                accessor: (e) =>
                    e.detail?.status ? statusBadge(e.detail.status) : "—",
            },
            {
                header: "Date de l'absence",
                accessor: (e) => formatDate(e.detail?.absence_date),
            },
            {
                header: "Enregistrée le",
                accessor: (e) => formatDateTime(e.created_at),
            },
        ],
        memberships: [
            {
                header: "Élève",
                accessor: (e) => e.detail?.student_name ?? "—",
            },
            { header: "Offre", accessor: (e) => e.detail?.offer_name ?? "—" },
            {
                header: "Début",
                accessor: (e) => formatDate(e.detail?.start_date),
            },
            {
                header: "Fin",
                accessor: (e) => formatDate(e.detail?.end_date),
            },
            {
                header: "Créée le",
                accessor: (e) => formatDateTime(e.created_at),
            },
        ],
    };

    return (
        <div className="mt-4 bg-white rounded-md p-4">
            <PerformanceStrip performance={journal.performance} />

            <div className="border-b border-gray-200 mb-4">
                <nav
                    className="-mb-px flex overflow-x-auto pb-1"
                    aria-label="Journal de l'assistant"
                >
                    {journalTabs.map((tab) => (
                        <TabButton
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            isActive={activeTab === tab.key}
                            icon={<tab.icon className="w-4 h-4 mr-1" />}
                            text={
                                tab.key === "history" ? (
                                    tab.label
                                ) : (
                                    <>
                                        <span>{tab.label}</span>{" "}
                                        <span className="text-sky-700 font-semibold tabular-nums">
                                            (
                                            {journal.categories[tab.key]
                                                ?.total ?? 0}
                                            )
                                        </span>
                                    </>
                                )
                            }
                        />
                    ))}
                </nav>
            </div>

            <div className="py-2">
                {activeTab === "history" ? (
                    <ActivityLogs logs={logs} defaultExpanded />
                ) : (
                    <DataTable
                        data={category.entries}
                        columns={detailColumns[activeTab] ?? []}
                        emptyMessage="Aucune création enregistrée pour cette catégorie"
                    />
                )}
            </div>
        </div>
    );
};

SingleAssistantPage.layout = (page) => (
    <DashboardLayout>{page}</DashboardLayout>
);

export default SingleAssistantPage;
