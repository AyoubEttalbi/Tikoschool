import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";
import Performance from "@/Components/Performance";
import DashboardLayout from '@/Layouts/DashboardLayout';
import FormModal from '@/Components/FormModal';
import MembershipCard from '@/Components/MembershipCard';
import StudentProfile from '@/Components/StudentProfile';
import { Users } from 'lucide-react';
// import studentProfile from "./studentProfile.png";
const SingleStudentPage = ({ student, Alllevels, Allclasses, Allschools, Alloffers, Allteachers,memberships }) => {
  const role = usePage().props.auth.user.role;
  console.log("xxxxx", student);
  console.log("route", Alllevels);
  console.log('Alloffers', Alloffers);
  console.log('Allteachers', Allteachers);
  console.log('memberships', memberships);
 
  return (
    <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
      {/* LEFT */}
      <div className="w-full xl:w-2/3">
        {/* TOP */}
        <div className="flex flex-col lg:flex-row gap-4">
          {/* USER INFO CARD */}
          <div className="bg-lamaSky py-6 px-4 rounded-md flex-1 flex gap-4">
            <div className="w-1/3">
              <img
                src={student.profile_image ? student.profile_image : "/studentProfile.png"}
                alt={student.name}
                width={144}
                height={144}
                className="w-36 h-36 rounded-full object-cover"
              />

            </div>

            <div className="w-2/3 flex flex-col justify-between gap-4">
              <h1 className="text-xl font-semibold">{student.firstName} {student.lastName}</h1>
              <p className="text-sm text-gray-500">
                {student.bio || "No bio available."}
              </p>
              <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/school.png" alt="school" width={14} height={14} />
                  <span>{Allschools.find(school => school.id === student.schoolId)?.name || ""}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/date.png" alt="Date" width={14} height={14} />
                  <span>
                    {student.created_at ?
                      new Intl.DateTimeFormat('en-GB', {
                        day: '2-digit',
                        month: 'long',
                        year: 'numeric'
                      }).format(new Date(student.created_at)) :
                      "03 January 2025"
                    }
                  </span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/mail.png" alt="Email" width={14} height={14} />
                  <span>{student.email || "user@gmail.com"}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/phone.png" alt="Phone" width={14} height={14} />
                  <span>{student.phoneNumber || "+1 234 567"}</span>
                </div>
              </div>
            </div>
            <FormModal table="student" type="update" icon={'updateIcon2'} data={student} id={student.id} levels={Alllevels} classes={Allclasses} schools={Allschools} />
          </div>
          {/* SMALL CARDS */}
          <div className="flex-1 flex gap-4 justify-between flex-wrap">
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleAttendance.png"
                alt="Attendance"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{student.attendance || "99%"}</h1>
                <span className="text-sm text-gray-400">Attendance</span>
              </div>
            </div>
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleBranch.png"
                alt="Grade"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{Alllevels.find(level => level.id === student.levelId)?.name || ""}</h1>
                <span className="text-sm text-gray-400">Level</span>
              </div>
            </div>
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleLesson.png"
                alt="Offer"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{student.memberships.length || "0"}</h1>
                <span className="text-sm text-gray-400">Offers</span>
              </div>
            </div>
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleClass.png"
                alt="Class"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{Allclasses.find(classs => classs.id === student.classId)?.name || "6A"}</h1>
                <span className="text-sm text-gray-400">Class</span>
              </div>
            </div>
          </div>
        </div>
        {/* BOTTOM */}
        <div className="mt-4 bg-white rounded-md p-4 h-fit flex flex-col justify-center items-start">
        <div className="w-full max-w-lg p-6 bg-white rounded-lg shadow-md border border-gray-200">
          <div className="flex justify-between items-center mb-6">
            <div className="flex items-center gap-2 text-blue-600 font-medium mr-4">
              
              <span className='flex flex-row'><Users className="h-5 w-5 mr-3" />Memberships:</span>
            </div>
            <FormModal table="membership" type="create" id={student.id} offers={Alloffers} teachers={Allteachers} studentId={student.id} />
          </div>
          {
            student.memberships ? (
              <MembershipCard Student_memberships={student.memberships} teachers={Allteachers} offers={Alloffers} studentId={student.id}/>
            ) : (
              <div className="flex flex-col items-center gap-4">
                <img src="/student.png" alt="Student" width={64} height={64} className="mb-4" />
                <h1 className="text-2xl font-semibold text-gray-700">No Membership</h1>
              </div>
            )
          }
        </div>
        </div>
        
        {/* Student Profile with grades, invoices, and attendance */}
        <StudentProfile 
          Student_memberships={student.memberships} 
          invoices={student.invoices} 
          studentId={student.id} 
          studentClassId={student.classId}
          attendances={student.attendances}
          results={student.results}
        />

      </div>
      {/* RIGHT */}
      <div className="w-full xl:w-1/3 flex flex-col gap-4">
        <div className="bg-white p-4 rounded-md">
          <h1 className="text-xl font-semibold">Shortcuts</h1>
          <div className="mt-4 flex gap-4 flex-wrap text-xs text-gray-500">
            <Link className="p-3 rounded-md bg-lamaSkyLight" href="/lessons">
              Student&apos;s Lessons
            </Link>
            <Link className="p-3 rounded-md bg-lamaPurpleLight" href="/teachers">
              Student&apos;s Teachers
            </Link>
            <Link className="p-3 rounded-md bg-pink-50" href="/exams">
              Student&apos;s Exams
            </Link>
            <Link className="p-3 rounded-md bg-lamaSkyLight" href="/assignments">
              Student&apos;s Assignments
            </Link>
            <Link className="p-3 rounded-md bg-lamaYellowLight" href="/results">
              Student&apos;s Results
            </Link>
          </div>
        </div>
        <Performance />
        <Announcements />
      </div>

    </div>
  );
};
SingleStudentPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default SingleStudentPage;