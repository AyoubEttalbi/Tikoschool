import React, { Suspense, lazy, useState } from "react";
const Announcements = lazy(() => import("@/Pages/Menu/Announcements/Announcements"));
const AttendanceChart = lazy(() => import("@/Pages/Attendance/AttendanceChart"));
const CountChart = lazy(() => import("@/Pages/Invoices/CountChart"));
const FinanceChart = lazy(() => import("@/Components/FinanceChart"));
const UserCard = lazy(() => import("@/Components/UserCard"));
const EventCalendar = lazy(() => import("@/Components/EventCalendar"));
const MostSellingOffersChart = lazy(() => import("@/Components/MostSellingOffersChart"));
import { usePage, router } from "@inertiajs/react";

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
            {/* School Selector - centered and styled for smoothness */}
            <div className=" flex mb-6 mt-2 ml-2">
                <div className="flex items-center gap-2 bg-white shadow rounded-lg px-4 py-3 transition-all duration-300 border border-gray-100">
                    <label htmlFor="school-select" className="mr-2 font-semibold text-gray-700 whitespace-nowrap">École :</label>
                    <select
                        id="school-select"
                        value={selectedSchool}
                        onChange={handleSchoolChange}
                        className="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400 transition-all duration-200 text-gray-700 bg-white shadow-sm hover:border-blue-400"
                    >
                        {props.schools?.map((school) => (
                            <option key={school.id} value={school.id}>
                                {school.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="p-4 flex gap-4 flex-col md:flex-row">
                {/* LEFT */}
                <div className="w-full lg:w-2/3 flex flex-col gap-8">
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
                            <FinanceChart schoolId={selectedSchool} />
                        </Suspense>
                    </div>
                    {/* MOST SELLING OFFERS CHART */}
                    <div className="w-full bg-white h-[400px]">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <MostSellingOffersChart schoolId={selectedSchool} />
                        </Suspense>
                    </div>
                </div>
                {/* RIGHT */}
                <div className="w-full lg:w-1/3 flex flex-col gap-8">
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
                </div>
            </div>
        </div>
    );
};

export default Content;
