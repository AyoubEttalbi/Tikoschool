import Announcements from "@/Components/Announcements";
import AttendanceChart from "@/Components/AttendanceChart";
import CountChart from "@/Components/CountChart";
import FinanceChart from "@/Components/FinanceChart";
import UserCard from "@/Components/UserCard";
import React from "react";


const Content = () => {
  return (
    <div className="p-4 flex gap-4 flex-col md:flex-row ">
      {/* LEFT */}
      <div className="w-full lg:w-2/3 flex flex-col gap-8">
        {/* USER CARDS */}
        <div className="flex gap-4 justify-between bg-white flex-wrap">
          <UserCard type="student" />
          <UserCard type="teacher" />
          <UserCard type="parent" />
          <UserCard type="staff" />
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
        {/* BOTTOM CHART */}
        <div className="w-full bg-white h-[500px]">
          <FinanceChart />
        </div>
      </div>
      {/* RIGHT */}
      <div className="w-full bg-white lg:w-1/3 flex flex-col gap-8">
        <Announcements/>
      </div>
    </div>
  );
};

export default Content;