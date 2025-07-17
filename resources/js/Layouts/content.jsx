import React, { Suspense, lazy, useState } from "react";
const Announcements = lazy(() => import("@/Pages/Menu/Announcements/Announcements"));
const AttendanceChart = lazy(() => import("@/Pages/Attendance/AttendanceChart"));
const CountChart = lazy(() => import("@/Pages/Invoices/CountChart"));
const FinanceChart = lazy(() => import("@/Components/FinanceChart"));
const UserCard = lazy(() => import("@/Components/UserCard"));
const EventCalendar = lazy(() => import("@/Components/EventCalendar"));
const MostSellingOffersChart = lazy(() => import("@/Components/MostSellingOffersChart"));
import { usePage, router } from "@inertiajs/react";
import { School } from "lucide-react";

const Content = () => {
    const { props } = usePage();
    const { auth } = props;
    const userRole = auth.user.role;
    // Use selected school from props if available, else default to first
    const [selectedSchool, setSelectedSchool] = useState(props.selectedSchoolId || props.schools?.[0]?.id || "");

    // Handler for school change: reload dashboard with selected school
    const handleSchoolChange = (e) => {
        setSelectedSchool(e.target.value);
        router.get(route("dashboard"), { school_id: e.target.value }, { preserveState: true, preserveScroll: true });
    };

    return (
        <div>
            {/* Top bar with dashboard title and school selector */}
            <div className="flex justify-between items-center mb-6 mt-2 px-4">
                <h1 className="text-2xl font-bold text-gray-800">Tableau de bord</h1>
                {/* School Selector */}
                {props.schools && props.schools.length > 0 && (
                    <div className="flex items-center gap-2 ml-auto sticky top-4 z-30 bg-white/95 shadow-md rounded-xl px-4 py-2 border border-blue-100" style={{ minWidth: 220 }}>
                        <School className="w-5 h-5 text-blue-500" />
                        <label htmlFor="school-select" className="text-sm font-semibold text-slate-700 mr-2">École</label>
                        <select
                            id="school-select"
                            value={selectedSchool}
                            onChange={handleSchoolChange}
                            className="px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white text-sm shadow-sm"
                        >
                            <option value="all">Toutes les écoles</option>
                            {props.schools.map((school) => (
                                <option key={school.id} value={school.id}>
                                    {school.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </div>
            <div className="p-4 flex gap-4 flex-col md:flex-row">
                {/* LEFT */}
                <div className="w-full lg:w-full lg:px-12 flex flex-col gap-8">
                    {/* USER CARDS */}
                    <div className="flex gap-4 justify-between bg-white flex-wrap">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <UserCard
                                type="student"
                                counts={props.studentCounts}
                                totalCount={props.totalStudentCount}
                                schoolId={selectedSchool}
                            />
                        </Suspense>
                        <Suspense fallback={<div>Chargement...</div>}>
                            <UserCard
                                type="teacher"
                                counts={props.teacherCounts}
                                totalCount={props.totalTeacherCount}
                                schoolId={selectedSchool}
                            />
                        </Suspense>
                        <Suspense fallback={<div>Chargement...</div>}>
                            <UserCard
                                type="assistant"
                                counts={props.assistantCounts}
                                totalCount={props.totalAssistantCount}
                                schoolId={selectedSchool}
                            />
                        </Suspense>
                    </div>
                    {/* MIDDLE CHARTS */}
                    <div className="flex gap-4 flex-col bg-white lg:flex-row">
                        {/* COUNT CHART */}
                        <div className="w-full lg:w-1/3 h-[450px]">
                            <Suspense fallback={<div>Chargement...</div>}>
                                <CountChart schoolId={selectedSchool} />
                            </Suspense>
                        </div>
                        {/* ATTENDANCE CHART */}
                        <div className="w-full lg:w-2/3 bg-white h-[450px]">
                            <Suspense fallback={<div>Chargement...</div>}>
                                <AttendanceChart schoolId={selectedSchool} />
                            </Suspense>
                        </div>
                    </div>
                    {/* BOTTOM CHARTS */}
                    <div className="w-full bg-white h-[500px]">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <FinanceChart schoolId={selectedSchool} monthlyIncomes={props.monthlyIncomes} />
                        </Suspense>
                    </div>
                    {/* MOST SELLING OFFERS CHART */}
                    <div className="w-full bg-white h-[400px]">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <MostSellingOffersChart schoolId={selectedSchool} mostSellingOffers={props.mostSellingOffers} />
                        </Suspense>
                    </div>
                    {/* RIGHT */}
                {/* <div className="w-full flex flex-row gap-4">
                    <Suspense fallback={<div>Chargement...</div>}>
                        <EventCalendar schoolId={selectedSchool} />
                    </Suspense>
                    <Suspense fallback={<div>Chargement...</div>}>
                        <Announcements
                            announcements={props.announcements}
                            userRole={userRole}
                            limit={5}
                            schoolId={selectedSchool}
                        />
                    </Suspense>
                </div> */}
                </div>
                
            </div>
        </div>
    );
};

export default Content;
