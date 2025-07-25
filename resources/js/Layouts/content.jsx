import Announcements from "@/Pages/Menu/Announcements/Announcements";
import AttendanceChart from "@/Components/AttendanceChart";
import CountChart from "@/Components/CountChart";
import FinanceChart from "@/Components/FinanceChart";
import UserCard from "@/Components/UserCard";
import React from "react";
import EventCalendar from "@/Components/EventCalendar";
import { usePage } from "@inertiajs/react";
import MostSellingOffersChart from "@/Components/MostSellingOffersChart";

const Content = () => {
  const { props } = usePage();
  const { auth } = usePage().props;
  const userRole = auth.user.role;
  console.log('roleee', userRole);
  
  return (
    <div className="p-4 flex gap-4 flex-col md:flex-row">
      {/* LEFT */}
      <div className="w-full lg:w-2/3 flex flex-col gap-8">
        {/* USER CARDS */}
        <div className="flex gap-4 justify-between bg-white flex-wrap">
          <UserCard
            type="student"
            counts={props.studentCounts}
            totalCount={props.totalStudentCount}
            schools={props.schools}
          />
          <UserCard
            type="teacher"
            counts={props.teacherCounts}
            totalCount={props.totalTeacherCount}
            schools={props.schools}
          />
          <UserCard
            type="assistant"
            counts={props.assistantCounts}
            totalCount={props.totalAssistantCount}
            schools={props.schools}
          />
        </div>
        {/* MIDDLE CHARTS */}
        <div className="flex gap-4 flex-col bg-white lg:flex-row">
          {/* COUNT CHART */}
          <div className="w-full lg:w-1/3 h-[450px]">
            <CountChart />
          </div>
          {/* ATTENDANCE CHART */}
          <div className="w-full lg:w-2/3 bg-white h-[450px]">
            <AttendanceChart />
          </div>
        </div>
        {/* BOTTOM CHARTS */}
        <div className="w-full bg-white h-[500px]">
          <FinanceChart />
        </div>
        {/* MOST SELLING OFFERS CHART */}
        <div className="w-full bg-white h-[400px]">
          <MostSellingOffersChart />
        </div>
      </div>
      {/* RIGHT */}
      <div className="w-full lg:w-1/3 flex flex-col gap-8">
        <EventCalendar />
        
        <Announcements 
          announcements={props.announcements} 
          userRole={userRole} 
          limit={5} 
        />
      </div>
    </div>
  );
};

export default Content;