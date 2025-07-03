import React, { Suspense, lazy } from "react";
const Announcements = lazy(() => import("@/Pages/Menu/Announcements/Announcements"));
const AttendanceChart = lazy(() => import("@/Pages/Attendance/AttendanceChart"));
const CountChart = lazy(() => import("@/Pages/Invoices/CountChart"));
const FinanceChart = lazy(() => import("@/Components/FinanceChart"));
const UserCard = lazy(() => import("@/Components/UserCard"));
const EventCalendar = lazy(() => import("@/Components/EventCalendar"));
const MostSellingOffersChart = lazy(() => import("@/Components/MostSellingOffersChart"));
import { usePage } from "@inertiajs/react";

const Content = () => {
    const { props } = usePage();
    const { auth } = usePage().props;
    const userRole = auth.user.role;
    return (
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
                            schools={props.schools}
                        />
                    </Suspense>
                    <Suspense fallback={<div>Chargement...</div>}>
                        <UserCard
                            type="teacher"
                            counts={props.teacherCounts}
                            totalCount={props.totalTeacherCount}
                            schools={props.schools}
                        />
                    </Suspense>
                    <Suspense fallback={<div>Chargement...</div>}>
                        <UserCard
                            type="assistant"
                            counts={props.assistantCounts}
                            totalCount={props.totalAssistantCount}
                            schools={props.schools}
                        />
                    </Suspense>
                </div>
                {/* MIDDLE CHARTS */}
                <div className="flex gap-4 flex-col bg-white lg:flex-row">
                    {/* COUNT CHART */}
                    <div className="w-full lg:w-1/3 h-[450px]">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <CountChart />
                        </Suspense>
                    </div>
                    {/* ATTENDANCE CHART */}
                    <div className="w-full lg:w-2/3 bg-white h-[450px]">
                        <Suspense fallback={<div>Chargement...</div>}>
                            <AttendanceChart />
                        </Suspense>
                    </div>
                </div>
                {/* BOTTOM CHARTS */}
                <div className="w-full bg-white h-[500px]">
                    <Suspense fallback={<div>Chargement...</div>}>
                        <FinanceChart />
                    </Suspense>
                </div>
                {/* MOST SELLING OFFERS CHART */}
                <div className="w-full bg-white h-[400px]">
                    <Suspense fallback={<div>Chargement...</div>}>
                        <MostSellingOffersChart />
                    </Suspense>
                </div>
            </div>
            {/* RIGHT */}
            <div className="w-full lg:w-1/3 flex flex-col gap-8">
                <Suspense fallback={<div>Chargement...</div>}>
                    <EventCalendar />
                </Suspense>
                <Suspense fallback={<div>Chargement...</div>}>
                    <Announcements
                        announcements={props.announcements}
                        userRole={userRole}
                        limit={5}
                    />
                </Suspense>
            </div>
        </div>
    );
};

export default Content;
