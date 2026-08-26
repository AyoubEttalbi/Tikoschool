import React, { Suspense } from "react";
const FormModal = React.lazy(() => import("@/Components/FormModal"));
const TeacherProfile = React.lazy(() => import("@/Components/TeacherProfile"));
import DashboardLayout from "@/Layouts/DashboardLayout";
import ProfileImageLightbox from "@/Components/ProfileImageLightbox";
import { usePage, router } from "@inertiajs/react";

const SingleTeacherPage = ({
    teacher = {},
    classes,
    subjects,
    schools,
    invoices,
    invoiceStats = {},
    selectedSchool,
    teacherSchools = [],
    recurringTransactions = [],
    transactions,
    filterOptions = {},
    filters = {},
}) => {
    const { auth, flash } = usePage().props;
    const role = auth.user.role;

    // Handle school change
    const handleSchoolChange = () => {
        router.visit("/select-profile");
    };

    // A `history.replaceState(null, '', route('teachers.index'))` used to run here on mount,
    // to "clean up history for the back button". It rewrote THIS page's address bar to the
    // teachers LIST url while still showing the teacher's profile — so the URL no longer
    // identified the page you were on, and reloading a profile served the list instead.
    //
    // It was invisible for a long time because the invoice table's mount-fire immediately
    // re-navigated to /teachers/{id}?…filters and put a correct URL back. Removing that
    // duplicate request left the corrupted URL with nothing to repair it.
    //
    // Nothing is needed in its place: Inertia pushes a history entry on the list -> profile
    // visit, so Back already returns to the list.

    return (
        <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
            {/* GAUCHE */}
            <div className="w-full">
                {/* Flash: success/error redirects used to land here invisibly —
                    the page rendered no banner at all. */}
                {flash?.success && (
                    <div className="mb-3 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-600">
                        {flash.error}
                    </div>
                )}
{/* Bannière de sélection d'école */}
                {selectedSchool &&
                    teacher.schools &&
                    teacher.schools.length > 1 && (
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
                {/* HAUT */}
                <div className="flex flex-col lg:flex-row gap-4">
                    {/* CARTE INFO UTILISATEUR - Responsive: mobile style only on small screens */}
                    <div className="bg-lamaSky py-4 px-2 rounded-md flex-1 flex-col flex items-center sm:bg-lamaSky sm:py-6 sm:px-4 sm:rounded-md sm:flex-row sm:items-stretch sm:gap-4 sm:flex">
                        {/* Avatar — click for quick view */}
                        <div className="w-full flex justify-center sm:justify-start sm:w-1/3 mb-2 sm:mb-0">
                            <ProfileImageLightbox
                                src={teacher.profile_image ? teacher.profile_image : "/teacherPrfile2.png"}
                                alt={`${teacher.first_name} ${teacher.last_name}`}
                                enabled={Boolean(teacher.profile_image)}
                                className="rounded-full object-cover w-16 h-16 mb-2 sm:w-36 sm:h-36 sm:mb-0"
                            />
                        </div>
                        {/* Info */}
                        <div className="w-full sm:w-2/3 flex flex-col justify-between gap-2 sm:gap-4 items-center sm:items-start">
                            <div className="flex flex-col items-center sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                                <h1 className="text-base sm:text-xl font-semibold text-center sm:text-left">
                                    {teacher.first_name} {teacher.last_name}
                                </h1>
                                <span
                                    className={`inline-block mt-1 sm:mt-0 rounded-full px-3 py-0.5 text-xs font-medium ${
                                        teacher.status === "active"
                                            ? "bg-green-100 text-green-800 border border-green-200"
                                            : "bg-red-100 text-red-800 border border-red-200"
                                    }`}
                                >
                                    {teacher.status === "active" ? "Actif" : "Inactif"}
                                </span>
                            </div>
                            <div className="border-t border-white/40 w-full my-2 sm:hidden"></div>
                            {teacher.bio && (
                                <p className="text-xs sm:text-sm text-gray-500 text-center sm:text-left">
                                    {teacher.bio}
                                </p>
                            )}
                            <div className="flex flex-col gap-1 w-full text-sm sm:text-xs font-medium">
                                <div className="flex items-center gap-1 justify-center sm:justify-start">
                                    <img src="/school.png" alt="École" className="w-4 h-4" />
                                    <span>
                                        {teacher.schools && teacher.schools.length > 0
                                            ? teacher.schools.map((school) => school.name).join(", ")
                                            : "Aucune école assignée"}
                                    </span>
                                </div>
                                <div className="flex items-center gap-1 justify-center sm:justify-start">
                                    <img src="/date.png" alt="Date" className="w-4 h-4" />
                                    <span>
                                        {teacher.created_at
                                            ? new Intl.DateTimeFormat("fr-FR", { month: "long", year: "numeric" }).format(new Date(teacher.created_at))
                                            : "N/A"}
                                    </span>
                                </div>
                                <a href={`mailto:${teacher.email}`} className="flex items-center gap-1 justify-center sm:justify-start text-blue-700 hover:underline py-1">
                                    <img src="/mail.png" alt="E-mail" className="w-4 h-4" />
                                    <span>{teacher.email}</span>
                                </a>
                                <a href={`tel:${teacher.phone_number}`} className="flex items-center gap-1 justify-center sm:justify-start text-blue-700 hover:underline py-1">
                                    <img src="/phone.png" alt="Téléphone" className="w-4 h-4" />
                                    <span>{teacher.phone_number}</span>
                                </a>
                            </div>
                        </div>
                        {role === "admin" && (
                            <Suspense fallback={<span>Chargement...</span>}>
                                <FormModal
                                    table="teacher"
                                    type="update"
                                    data={teacher}
                                    schools={schools}
                                    classes={classes}
                                    subjects={subjects}
                                    icon={"updateIcon2"}
                                />
                            </Suspense>
                        )}
                    </div>

                    {/* PETITES CARTES */}
                    <div className="flex-1 flex gap-4 justify-between flex-wrap">
                        <InfoCard
                            icon="/singleAttendance.png"
                            label="Nombre d'élèves"
                            value={teacher.totalStudents || 0}
                        />
                        <InfoCard
                            icon="/singleBranch.png"
                            label="Matières"
                            value={
                                teacher.subjects ? teacher.subjects.length : 0
                            }
                        />
                        <InfoCard
                            icon="/singleClass.png"
                            label="Classes"
                            value={teacher.classes ? teacher.classes.length : 0}
                        />
                        <InfoCard
                            icon="/singleLesson.png"
                            label="Écoles"
                            value={teacher.schools ? teacher.schools.length : 0}
                        />
                    </div>
                </div>
                <div className="mt-4">
                    <Suspense fallback={<span>Chargement...</span>}>
                        <TeacherProfile
                            invoices={
                                invoices && invoices.data ? Object.values(invoices.data) : (invoices || [])
                            }
                            paginate={
                                invoices && invoices.links ? invoices.links : []
                            }
                            transactions={transactions}
                            teacher={teacher}
                            filterOptions={filterOptions}
                            filters={filters}
                            invoiceStats={invoiceStats}
                        />
                    </Suspense>
                </div>
            </div>
        </div>
    );
};

const InfoCard = ({ icon, label, value }) => (
    <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
        <img src={icon} alt="" width={24} height={24} className="w-6 h-6" />
        <div>
            <h1 className="text-xl font-semibold">{value}</h1>
            <span className="text-sm text-gray-400">{label}</span>
        </div>
    </div>
);

SingleTeacherPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default SingleTeacherPage;
